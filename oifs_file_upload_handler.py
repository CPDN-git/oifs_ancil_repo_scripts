#!/usr/bin/env python3

import pymysql
import pymysql.converters
import os, sys
import argparse
import hashlib
import shutil
import datetime
import xml.etree.ElementTree as ET
import tarfile
import subprocess
import zipfile
import re
import json
import tempfile
from contextlib import contextmanager

from eccodes_config import discover_eccodes

site='main'


def connect_database():
    """Read protected configuration and open the ancillary database on demand."""
    db_config = '/storage/www/cpdnboinc/ancil_batch_user_config.xml'
    tree = ET.parse(db_config)
    db_host = tree.find('ancil_db_host').text
    connection_params = {
        'host': db_host,
        'user': tree.find('ancil_user').text,
        'conv': pymysql.converters.conversions,
        'passwd': tree.find('ancil_passwd').text,
        'db': tree.find('ancil_db_name').text,
    }
    if db_host == 'localhost':
        connection_params['unix_socket'] = '/storage/mysql/mysql.sock'
    return pymysql.connect(**connection_params)


class PublicationRecoveryRequired(RuntimeError):
    """Keep staged outputs when database/file state needs operator reconciliation."""


def file_md5(filename):
    """Hash large files incrementally, without a separate md5sum executable."""
    digest = hashlib.md5()
    with open(filename, 'rb') as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b''):
            digest.update(block)
    return digest.hexdigest()


@contextmanager
def staged_outputs():
    """Own temporary output directories, retaining them only for manual recovery."""
    directories = []
    keep = False
    try:
        yield directories
    except PublicationRecoveryRequired:
        keep = True
        raise
    finally:
        if not keep:
            for directory in directories:
                try:
                    shutil.rmtree(directory)
                except OSError as error:
                    print('Warning: could not remove output staging directory '
                          + directory + ': ' + str(error), file=sys.stderr)


def stage_destination(destination, directories):
    """Create the destination directory and reserve private output staging beside it."""
    if os.path.lexists(destination):
        raise FileExistsError('Ancillary output already exists: ' + destination)
    cursor.execute('SELECT file_name FROM oifs_ancil_files WHERE file_name = %s LIMIT 1',
                   (os.path.basename(destination),))
    if cursor.fetchone() is not None:
        raise FileExistsError('Ancillary filename is already registered: ' + os.path.basename(destination))
    parent = os.path.dirname(destination)
    os.makedirs(parent, exist_ok=True)
    if not os.path.isdir(parent):
        raise NotADirectoryError('Ancillary destination is not a directory: ' + parent)
    directory = tempfile.mkdtemp(prefix='.oifs-output-', dir=parent)
    directories.append(directory)
    return os.path.join(directory, os.path.basename(destination))


def write_recovery_record(filename, record):
    """Replace our recovery record atomically; never record credentials or SQL values."""
    descriptor, temporary = tempfile.mkstemp(prefix='.oifs-record-',
                                           dir=os.path.dirname(filename))
    try:
        with os.fdopen(descriptor, 'w', encoding='utf-8') as stream:
            json.dump(record, stream, indent=2)
            stream.write('\n')
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary, filename)
    finally:
        if os.path.exists(temporary):
            os.remove(temporary)


def publish_outputs(plans, source):
    """Publish staged files and register one upload in one database transaction.

    Filesystem publication and a MySQL commit cannot be one atomic operation.
    Remove our files after confirmed rollback before commit is attempted. Retain
    them and the recovery record if commit acknowledgement/rollback is uncertain.
    """
    if not plans:
        raise ValueError('No ancillary outputs were produced; the upload is retained.')
    # Rollback must be supported; do not silently rely on a nontransactional table.
    cursor.execute('SHOW TABLE STATUS WHERE Name = %s', ('oifs_ancil_files',))
    table = cursor.fetchone()
    if not table or str(table['Engine']).upper() != 'INNODB':
        raise RuntimeError('Ancillary publication requires an InnoDB oifs_ancil_files table.')

    descriptor, recovery = tempfile.mkstemp(prefix='.oifs-publication-', suffix='.json',
                                          dir=os.path.dirname(source))
    os.close(descriptor)
    record = {'source': source, 'state': 'prepared', 'outputs': [
        {'staged': plan['staged'], 'destination': plan['destination'], 'md5': plan['md5']}
        for plan in plans]}
    transaction_started = False
    commit_attempted = False
    try:
        write_recovery_record(recovery, record)
        db.begin()
        transaction_started = True
        for plan in plans:
            cursor.execute(plan['query'], plan['parameters'])
        for plan in plans:
            # Staging is on the destination filesystem. link() publishes complete
            # bytes atomically and fails if ANY destination entry already exists.
            os.link(plan['staged'], plan['destination'])
        record['state'] = 'commit_pending'
        write_recovery_record(recovery, record)
        commit_attempted = True
        db.commit()
    except BaseException as error:
        rollback_error = None
        if transaction_started:
            try:
                db.rollback()
            except Exception as failure:
                rollback_error = failure
        if commit_attempted or rollback_error is not None:
            detail = ('; rollback also failed: ' + str(rollback_error)) if rollback_error else ''
            raise PublicationRecoveryRequired(
                'Publication outcome needs manual review' + detail
                + '. Input and outputs retained. Recovery record: ' + recovery) from error
        cleanup_errors = []
        for plan in plans:
            try:
                # Never remove a pre-existing file or a replacement made elsewhere.
                if os.path.lexists(plan['destination']) and os.path.samestat(
                        os.stat(plan['staged']), os.stat(plan['destination'], follow_symlinks=False)):
                    os.remove(plan['destination'])
            except OSError as failure:
                cleanup_errors.append(str(failure))
        if cleanup_errors:
            raise PublicationRecoveryRequired(
                'Database rollback completed, but output cleanup needs manual review: '
                + '; '.join(cleanup_errors) + '. Recovery record: ' + recovery) from error
        try:
            os.remove(recovery)
        except OSError as failure:
            print('Warning: rolled back; recovery record could not be removed: '
                  + recovery + ': ' + str(failure), file=sys.stderr)
        raise
    else:
        try:
            os.remove(recovery)
        except OSError as error:
            print('Warning: publication committed; remove the recovery record after review: '
                  + recovery + ': ' + str(error), file=sys.stderr)


def remove_completed_input(filename, directory=None):
    """Cleanup after an acknowledged commit must not turn success into a retry."""
    for path, remove in ((filename, os.remove), (directory, shutil.rmtree)):
        if path is None:
            continue
        try:
            remove(path)
        except OSError as error:
            print('Warning: publication committed; input cleanup needs review: '
                  + path + ': ' + str(error), file=sys.stderr)


def unpack_upload_file(Args):
    dates = []
    tmp_dir = '/storage/www/cpdnboinc/tmp_ancil_upload/'
    ancil_dir = '/storage/cpdn_ancil_files/oifs_ancil_files/' + Args.ancil_type
    source = tmp_dir + Args.ulfile
    print('Extracting tarfile ' + Args.ulfile + ' to ' + tmp_dir)
    with tarfile.open(source) as tar:
        tar.extractall(path=tmp_dir)
    rootDir = tmp_dir + Args.exptid

    # Walk the untarred file and create zips. Preserve explicit analysis directories
    # and the implicit single-analysis value 00 used by the original implementation.
    with staged_outputs() as directories:
        plans = []
        for dirName, subdirList, fileList in os.walk(rootDir, topdown=True):
            if dirName == rootDir:
                dates = subdirList
            date = dirName.split('/')[-1]
            if date in dates:
                analysis_nos = [i for i in subdirList if i != 'ecmwf']
                if 'ecmwf' in subdirList:
                    print('Removing ecmwf directory...')
                    shutil.rmtree(dirName + '/ecmwf')
                analyses = [(number, dirName + '/' + number) for number in analysis_nos]
                if not analyses:
                    analyses = [('00', dirName)]
                for analysis_no, analysis_dir in analyses:
                    zipname = 'ic_' + Args.exptid + '_' + date + '_' + analysis_no
                    adir = ancil_dir + '/' + Args.exptid + '/' + date + '/' + analysis_no + '/'
                    destination = adir + zipname + '.zip'
                    if os.path.lexists(destination):
                        raise FileExistsError('Ancillary output already exists: ' + destination)
                    grib_info = get_grib_info(Args.exptid, analysis_no, analysis_dir)
                    staged = stage_destination(destination, directories)
                    print('Making zipfile ' + zipname)
                    shutil.make_archive(staged[:-4], 'zip', analysis_dir)
                    md5sum = file_md5(staged)
                    query, parameters = get_query(Args, grib_info, zipname + '.zip', md5sum)
                    plans.append({'staged': staged, 'destination': destination,
                                  'query': query, 'parameters': parameters, 'md5': md5sum})
        # No initial ZIP or database row is committed independently of this upload.
        publish_outputs(plans, source)
    print('Cleaning up')
    remove_completed_input(source, rootDir)

def consistency_check(ic_files,exptid,ddir):
    print("Performing consistency check")
    expts=[]
    start_dates=[]
    for ic_file in ic_files:
        print("Checking %s file" %ic_file)
        info=subprocess.check_output(
                [grib_ls_path,"-w", "count=1", "-p", "experimentVersionNumber,dataDate,dataTime",ddir+"/"+ic_file],
                text=True)
        lines=info.split("\n")
        line=lines[2].split()
        #Bypass exptid check for ICMCL file as fields can have climatologies with exptid of "0001" and be valid.
        #if ic_file != ic_files[-1]:
        expts.append(line[0])
        hour=get_hour(line[-1])
        start_date=line[1]+hour
        start_dates.append(start_date)
    # print("Experiment IDs: ",set(expts))
    if len(set(expts)) != 1:
        raise ValueError("Inconsistent experiment IDs in initial files")
    if expts[0] != exptid:
        raise ValueError("Experiment ID (%s) is inconsistent with grib files (%s)" % (exptid, expts[0]))
     
    # print("Start dates: ",set(start_dates))
    if len(set(start_dates)) != 1:
        raise ValueError("Inconsistent start dates in initial files")
     
    return start_dates[0]
    
def get_grib_info(exptid,analysis_no,ddir):
    file_info={}
    ICMSH_file="ICMSH"+exptid+"INIT"
    ICMGG_file="ICMGG"+exptid+"INIT"
    ICMGGUA_file="ICMGG"+exptid+"INIUA"
    ICMCL_file="ICMCL"+exptid+"INIT"

    # Check exptid and start date consistency between files
    #start_date=consistency_check([ICMSH_file,ICMGG_file,ICMGGUA_file, ICMCL_file],exptid,ddir)
    start_date=consistency_check([ICMSH_file,ICMGG_file,ICMGGUA_file],exptid,ddir)
    
    print("Extracting metadata from Grib files")
    file_info["start_date"]=start_date
    file_info["exptid"]=exptid
    file_info["analysis_number"]=analysis_no

    info=subprocess.check_output(
            [grib_ls_path,"-w", "count=1", "-p", "perturbationNumber,M",ddir+"/"+ICMSH_file],
            text=True)
    ICMSH_lines=info.split("\n")
    ICMSH_line=ICMSH_lines[2].split()
    
    analysis_num=ICMSH_line[0].zfill(3)
    # Check analysis number consistent with the file name
    #assert (analysis_num==analysis_no),"Analysis perturbation number (%s) is inconsistent with ICMSH grib file (%s)" %(analysis_no,analysis_num) 

    ICMSH_hres=ICMSH_line[-1]
    file_info["spectral_horizontal_resolution"]=ICMSH_hres

    # Get the end date from the ICMCL file
    info=subprocess.check_output(
            [grib_ls_path,"-w", "shortName=stl1", "-p", "shortName,dataDate,dataTime",ddir+"/"+ICMCL_file],
            text=True)
    ICMCL_lines_data=info.split("messages")[0]
    ICMCL_lines=ICMCL_lines_data.split("\n")
    ICMCL_line=ICMCL_lines[-2].split()
    end_hour=get_hour(ICMCL_line[-1])
    file_info["end_date"]=ICMCL_line[1]+end_hour 
    
    # Get the vertical resolution from the ICMSH file
    info=subprocess.check_output(
            [grib_ls_path,"-w", "shortName=vo", "-p", "shortName,level",ddir+"/"+ICMSH_file],
            text=True)
    ICMSH_lines_data=info.split("messages")[0]
    ICMSH_lines=ICMSH_lines_data.split("\n")
    ICMSH_line=ICMSH_lines[-2].split()
    file_info["vertical_resolution"]="L"+ICMSH_line[-1]


    # Get the grid point horizonal resolution
    info=subprocess.check_output(
            [grib_ls_path,"-w", "count=1", "-p", "N,isOctahedral",ddir+"/"+ICMGG_file],
            text=True)
    ICMGG_lines_data=info.split("messages")[0]
    ICMGG_lines=ICMGG_lines_data.split("\n")
    ICMGG_line=ICMGG_lines[-2].split()

    # Get the grid type information
    if ICMGG_line[-1]=="0":
        ICMGG_grid="Tl"
        ICMGG_hres="N"+ICMGG_line[0]
    elif ICMGG_line[-1]=="1":
        ICMGG_grid="Tco"
        ICMGG_hres="O"+ICMGG_line[0]
    else:
        ICMGG_grid=""
        ICMGG_hres=""
    file_info["grid_type"]=ICMGG_grid

    # Get the grid point horizonal resolution
    info=subprocess.check_output(
            [grib_ls_path,"-w", "count=1", "-p", "N,isOctahedral",ddir+"/"+ICMGGUA_file],
            text=True)
    ICMGGUA_lines_data=info.split("messages")[0]
    ICMGGUA_lines=ICMGGUA_lines_data.split("\n")
    ICMGGUA_line=ICMGGUA_lines[-2].split()

    # Get the grid type information
    if ICMGGUA_line[-1]=="0":
        ICMGGUA_grid="Tl"
        ICMGGUA_hres="N"+ICMGGUA_line[0]
    elif ICMGG_line[-1]=="1":
        ICMGGUA_grid="Tco"
        ICMGGUA_hres="O"+ICMGGUA_line[0]
    else:
        ICMGGUA_grid=""
        ICMGGUA_hres=""

    if ICMGGUA_hres != ICMGG_hres:
        raise ValueError("Horizontal resolution in ICMGG grib file (%s) is inconsistent with Upper Air ICMGG grib file (%s)" % (ICMGG_hres, ICMGGUA_hres))
    
    file_info["gridpoint_horizontal_resolution"]=ICMGG_hres

    return file_info

def get_hour(time):
    if len(time)==1:
        hour=time.zfill(2)
    if len(time)==3:
        hour=time[0].zfill(2)
    if len(time)==4:
        hour=time[0:2]
    #print("Extracting hour "+hour+" from time "+time)
    return hour 

def get_query(Vars,GribInfo,fname,md5sum):
    if Vars.sub_type!="0":
        url = "https://"+site+".cpdn.org/oifs_ancil_files/"+Vars.ancil_type+"/"+Vars.sub_type+"/"+fname
    else:
        if Vars.ancil_type=="ic_ancil":
            expt_path=GribInfo['exptid']+"/"+GribInfo['start_date']+"/"+GribInfo['analysis_number']+"/"+fname
            url = "https://"+site+".cpdn.org/oifs_ancil_files/"+Vars.ancil_type+"/"+expt_path
        else:
            url = "https://"+site+".cpdn.org/oifs_ancil_files/"+Vars.ancil_type+"/"+fname

    query = (
        "INSERT INTO oifs_ancil_files ("
        "file_name, created_by, uploaded_by, description, ancil_type, "
        "ancil_sub_type, model_version_number, exptid, starting_analysis, "
        "analysis_perturbation_number, start_date, end_date, grid_type, "
        "spectral_horizontal_resolution, gridpoint_horizontal_resolution, "
        "vertical_resolution, md5sum, url) "
        "VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, "
        "%s, %s, %s, %s, %s, %s, %s, %s, %s)"
    )
    parameters = (
        fname, Vars.created_by, Vars.uploaded_by, Vars.file_desc,
        Vars.ancil_type, Vars.sub_type, Vars.model_version, GribInfo['exptid'],
        Vars.starting_analysis, GribInfo['analysis_number'],
        GribInfo['start_date'], GribInfo['end_date'], GribInfo['grid_type'],
        GribInfo['spectral_horizontal_resolution'],
        GribInfo['gridpoint_horizontal_resolution'],
        GribInfo['vertical_resolution'], md5sum, url,
    )
    return query, parameters


def upload_file(Vars):
    tmp_dir = '/storage/www/cpdnboinc/tmp_ancil_upload/'
    source = tmp_dir + Vars.ulfile
    if Vars.ancil_type != 'fullpos_namelist':
        with zipfile.ZipFile(source) as archive:
            ret = archive.testzip()
        if ret is not None:
            raise ValueError('Bad zip file. First bad file in zip: %s' % ret)

    print('Uploading file...')
    ancil_dir = '/storage/cpdn_ancil_files/oifs_ancil_files/' + Vars.ancil_type
    md5sum = file_md5(source)
    grid_hres_parts = re.split(r'(\d.*)', Vars.grid_hres)

    if Vars.ancil_type == 'ifsdata':
        adir = ancil_dir + '/' + Vars.sub_type
        url = 'https://' + site + '.cpdn.org/oifs_ancil_files/' + Vars.ancil_type + '/' + Vars.sub_type + '/' + Vars.ulfile
    else:
        adir = ancil_dir
        url = 'https://' + site + '.cpdn.org/oifs_ancil_files/' + Vars.ancil_type + '/' + Vars.ulfile
    query = (
        'INSERT INTO oifs_ancil_files ('
        'file_name, created_by, uploaded_by, description, ancil_type, '
        'ancil_sub_type, model_version_number, grid_type, '
        'spectral_horizontal_resolution, md5sum, url) '
        'VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)'
    )
    parameters = (
        Vars.ulfile, Vars.created_by, Vars.uploaded_by, Vars.file_desc,
        Vars.ancil_type, Vars.sub_type, Vars.model_version,
        grid_hres_parts[0], grid_hres_parts[1], md5sum, url,
    )
    destination = os.path.join(adir, Vars.ulfile)
    with staged_outputs() as directories:
        staged = stage_destination(destination, directories)
        # Retain the incoming file until both destination publication and DB commit
        # succeed. copy2 preserves the original mode/timestamps, as a move did.
        shutil.copy2(source, staged)
        publish_outputs([{'staged': staged, 'destination': destination,
                          'query': query, 'parameters': parameters, 'md5': md5sum}], source)
    remove_completed_input(source)


#Main controling function
def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("created_by", help="The person who created the file")
    parser.add_argument("uploaded_by", help="The persion who uploaded the file")
    parser.add_argument("model_version", help="The model version number")
    parser.add_argument("exptid", help="The ECMWF experiment id")
    parser.add_argument("starting_analysis", help="The model starting analysis")
    parser.add_argument("ancil_type", help="The ancillary file type")
    parser.add_argument("sub_type", help="The ancillary file sub-type")
    parser.add_argument("grid_hres", help="The spectral horizontal resolution and grid information")
    parser.add_argument("file_desc", help="The file description")
    parser.add_argument("ulfile", help="The upload file")
    args = parser.parse_args()

    global db, cursor, grib_ls_path
    db = connect_database()
    try:
        cursor = db.cursor(pymysql.cursors.DictCursor)
        try:
            if args.ancil_type == "ic_ancil":
                grib_ls_path, _ = discover_eccodes()
                unpack_upload_file(args)
            else:
                upload_file(args)
        finally:
            cursor.close()
    finally:
        db.close()

    print('Finished!')

#Washerboard function that allows main() to run on running this file
if __name__=="__main__":
  main()

