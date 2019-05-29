#!/usr/bin/python2.7

import MySQLdb
import os, sys
import numpy as np 
import argparse
import hashlib
import shutil
import datetime
import xml.etree.ElementTree as ET
import tarfile
import subprocess
import zipfile

site='dev'

# setup database connection
db_config='/storage/www/cpdnboinc_'+site+'/ancil_batch_user_config.xml'
tree=ET.parse(db_config)
db_host=tree.find('db_host').text
db_user=tree.find('db_user').text
db_passwd=tree.find('db_passwd').text
db_name=tree.find('db_name').text
db = MySQLdb.connect(db_host,db_user,db_passwd,db_name,33001)
cursor = db.cursor(MySQLdb.cursors.DictCursor)

def unpack_upload_file(Args):
    dates=[]
    tmp_dir="/storage/www/cpdnboinc_"+site+"/tmp_ancil_upload/"
    ancil_dir="/storage/www/cpdnboinc_"+site+"/oifs_ancil_files/"+Args.ancil_type
    print("Extracting tarfile "+Args.ulfile+" to "+tmp_dir)
    tar= tarfile.open(tmp_dir+Args.ulfile)
    tar.extractall(path=tmp_dir)
    tar.close()
    exptid=Args.ulfile.split('.')[0].split('_')[0]
    rootDir=tmp_dir+exptid

    # Walk the untarred file and create zips.
    for dirName, subdirList, fileList in os.walk(rootDir, topdown=True):             
        if dirName==rootDir:
            dates=subdirList
        date=dirName.split('/')[-1]
        if date in dates:
            analysis_nos=[i for i in subdirList if i != 'ecmwf']
	    if 'ecmwf' in subdirList:
                        print("Removing ecmwf directory...")
                        shutil.rmtree(dirName+'/ecmwf')
            if analysis_nos==[]:
                analysis_no='00'
                zipname="ic_"+exptid+"_"+date+"_"+analysis_no
		adir=ancil_dir+"/"+exptid+"/"+date+"/"+analysis_no+"/"
		grib_info=get_grib_info(exptid,analysis_no,dirName)
                if not os.path.exists(adir):
                        os.makedirs(adir)
		print("Making zipfile "+zipname)
		shutil.make_archive(adir+zipname,"zip",dirName)
	
		md5_info=subprocess.check_output(['md5sum',adir+zipname+'.zip']) 
		md5sum=md5_info.split()[0]
		query=get_query(Args,grib_info,zipname+'.zip',md5sum)
		try:
			print("Adding "+zipname+".zip to the database")
			cursor.execute(query)
		        db.commit()
		except Exception,e:
			print 'Error adding file:',zipname+".zip",e
        		db.rollback()
			os.remove(adir+zipname+'.zip')
			continue

            else:
                for analysis_no in analysis_nos:
                    zipname="ic_"+exptid+"_"+date+"_"+analysis_no
		    adir=ancil_dir+"/"+exptid+"/"+date+"/"+analysis_no+"/"
                    grib_info=get_grib_info(exptid,analysis_no,dirName+"/"+analysis_no)
		    if not os.path.exists(adir):
                        os.makedirs(adir)
		    print("Making zipfile "+zipname)
		    shutil.make_archive(adir+zipname,"zip",dirName+"/"+analysis_no)
	
		    md5_info=subprocess.check_output(['md5sum',adir+zipname+'.zip'])
                    md5sum=md5_info.split()[0]
                    query=get_query(Args,grib_info,zipname+'.zip',md5sum)
    		    try:
                        print("Adding "+zipname+".zip to the database")
                        cursor.execute(query)
                        db.commit()
                    except Exception,e:
                        print 'Error adding file:',zipname+".zip",e
                        db.rollback()
                        os.remove(adir+zipname+'.zip')
                        continue

    # Cleaning up tmp_dir
    print("Cleaning up")
    os.remove(tmp_dir+Args.ulfile)
    shutil.rmtree(tmp_dir+exptid)

def consistency_check(ic_files,exptid,ddir):
     print("Performing consistency check")
     expts=[]
     start_dates=[]
     for ic_file in ic_files:
	print("Checking %s file" %ic_file)
	info=subprocess.check_output(["/home/boinc/eccodes/bin/grib_ls","-w", "count=1", "-p", "experimentVersionNumber,dataDate,dataTime",ddir+"/"+ic_file])
	lines=info.split("\n")
	line=lines[2].split()
	#Bypass exptid check for ICMCL file as fields can have climatologies with exptid of "0001" and be valid.
	#if ic_file != ic_files[-1]:
	expts.append(line[0])
	hour=get_hour(line[-1])
	start_date=line[1]+hour
	start_dates.append(start_date)
    # print("Experiment IDs: ",set(expts))
     assert(len(set(expts))==1),"Inconsistent experiment IDs in initial files"
     assert(expts[0]==exptid),"Experiment ID (%s) is inconsistent with grib files (%s)" %(exptid,expts[0])
     
    # print("Start dates: ",set(start_dates))
     assert(len(set(start_dates))==1),"Inconsistent start dates in initial files"
     
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

    info=subprocess.check_output(["/home/boinc/eccodes/bin/grib_ls","-w", "count=1", "-p", "perturbationNumber,M",ddir+"/"+ICMSH_file])
    ICMSH_lines=info.split("\n")
    ICMSH_line=ICMSH_lines[2].split()
    
    analysis_num=ICMSH_line[0].zfill(2)
    # Check analysis number consistent with the file name
    #assert (analysis_num==analysis_no),"Analysis perturbation number (%s) is inconsistent with ICMSH grib file (%s)" %(analysis_no,analysis_num) 

    ICMSH_hres="T"+ICMSH_line[-1]
    file_info["spectral_horizontal_resolution"]=ICMSH_hres

    # Get the end date from the ICMCL file
    info=subprocess.check_output(["/home/boinc/eccodes/bin/grib_ls","-w", "shortName=stl1", "-p", "shortName,dataDate,dataTime",ddir+"/"+ICMCL_file])
    ICMCL_lines_data=info.split("messages")[0]
    ICMCL_lines=ICMCL_lines_data.split("\n")
    ICMCL_line=ICMCL_lines[-2].split()
    end_hour=get_hour(ICMCL_line[-1])
    file_info["end_date"]=ICMCL_line[1]+end_hour 
    
    # Get the vertical resolution from the ICMSH file
    info=subprocess.check_output(["/home/boinc/eccodes/bin/grib_ls","-w", "shortName=vo", "-p", "shortName,level",ddir+"/"+ICMSH_file])
    ICMSH_lines_data=info.split("messages")[0]
    ICMSH_lines=ICMSH_lines_data.split("\n")
    ICMSH_line=ICMSH_lines[-2].split()
    file_info["vertical_resolution"]="L"+ICMSH_line[-1]

    # Get the grid point horizonal resolution
    info=subprocess.check_output(["/home/boinc/eccodes/bin/grib_ls","-w", "count=1", "-p", "N",ddir+"/"+ICMGG_file])
    ICMGG_lines=info.split("\n")
    ICMGG_line=ICMGG_lines[2].split()
    ICMGG_hres="N"+ICMGG_line[0]

    # Get the grid point horizonal resolution
    info=subprocess.check_output(["/home/boinc/eccodes/bin/grib_ls","-w", "count=1", "-p", "N",ddir+"/"+ICMGGUA_file])
    ICMGGUA_lines=info.split("\n")
    ICMGGUA_line=ICMGGUA_lines[2].split()
    ICMGGUA_hres="N"+ICMGGUA_line[0]
    assert (ICMGGUA_hres==ICMGG_hres),"Horizontal resolution in ICMGG grib file (%s) is inconsistent with Upper Air ICMGG grib file (%s)" %(ICMGG_hres,ICMGGUA_hres)

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
	url = "http://"+site+".cpdn.org/oifs_ancil_files/"+Vars.ancil_type+"/"+Vars.sub_type+"/"+fname
    else:
        if Vars.ancil_type=="ic_ancil":
            expt_path=GribInfo['exptid']+"/"+GribInfo['start_date']+"/"+GribInfo['analysis_number']+"/"+fname
            url = "http://"+site+".cpdn.org/oifs_ancil_files/"+Vars.ancil_type+"/"+expt_path
        else:
            url = "http://"+site+".cpdn.org/oifs_ancil_files/"+Vars.ancil_type+"/"+fname

    query= 'insert into oifs_ancil_files (file_name, created_by, uploaded_by, description, ancil_type, ancil_sub_type, model_version_number, exptid, starting_analysis, analysis_perturbation_number, start_date, end_date, spectral_horizontal_resolution, gridpoint_horizontal_resolution, vertical_resolution, md5sum, url) '
    query=query+" values ('"+fname+"','"+Vars.created_by+"','"+Vars.uploaded_by+"','"+Vars.file_desc+"','"+Vars.ancil_type+"',"+Vars.sub_type+",'"+Vars.model_version+"','"+GribInfo['exptid']+"','"+Vars.starting_analysis+"','"+GribInfo['analysis_number']+"','"+GribInfo['start_date']+"','"+GribInfo['end_date']+"','"+GribInfo['spectral_horizontal_resolution']+"','"+GribInfo['gridpoint_horizontal_resolution']+"','"+GribInfo['vertical_resolution']+"','"+md5sum+"','"+url+"')"

    return query

def upload_file(Vars):
    tmp_dir="/storage/www/cpdnboinc_"+site+"/tmp_ancil_upload/"
    if Vars.ancil_type!="fullpos_namelist":
    	ulfile_zip=zipfile.ZipFile(tmp_dir+Vars.ulfile)
    	ret=ulfile_zip.testzip()
    	assert(ret is None),"Bad zip file. First bad file in zip: %s" % ret

    print("Uploading file...")    
    ancil_dir="/storage/www/cpdnboinc_"+site+"/oifs_ancil_files/"+Vars.ancil_type
    md5_info=subprocess.check_output(['md5sum',tmp_dir+Vars.ulfile])
    md5sum=md5_info.split()[0]
   
    if Vars.ancil_type=="ifsdata":
	adir=ancil_dir+"/"+Vars.sub_type
	url = "http://"+site+".cpdn.org/oifs_ancil_files/"+Vars.ancil_type+"/"+Vars.sub_type+"/"+Vars.ulfile
    else:
	adir=ancil_dir
	url = "http://"+site+".cpdn.org/oifs_ancil_files/"+Vars.ancil_type+"/"+Vars.ulfile
    query= 'insert into oifs_ancil_files (file_name, created_by, uploaded_by, description, ancil_type, ancil_sub_type, model_version_number, md5sum, url) '
    query=query+" values ('"+Vars.ulfile+"','"+Vars.created_by+"','"+Vars.uploaded_by+"','"+Vars.file_desc+"','"+Vars.ancil_type+"','"+Vars.sub_type+"','"+Vars.model_version+"','"+md5sum+"','"+url+"')"
    try:
	print("Adding "+Vars.ulfile+" to the database")
	print(query)
	cursor.execute(query)
	print("Moving file into the repository...")
        shutil.move(tmp_dir+Vars.ulfile,adir)
        db.commit()
    except Exception,e:
        print 'Error add file:',Vars.ulfile,e
        db.rollback()

    
    

#Main controling function
def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("created_by", help="The person who created the file")
    parser.add_argument("uploaded_by", help="The persion who uploaded the file")
    parser.add_argument("model_version", help="The model version number")
    parser.add_argument("starting_analysis", help="The model starting analysis")
    parser.add_argument("ancil_type", help="The ancillary file type")
    parser.add_argument("sub_type", help="The ancillary file sub-type")
    parser.add_argument("file_desc", help="The file description")
    parser.add_argument("ulfile", help="The upload file")
    args = parser.parse_args()

    if args.ancil_type == "ic_ancil":
        unpack_upload_file(args)
    else:
	upload_file(args)

    print('Finished!')

#Washerboard function that allows main() to run on running this file
if __name__=="__main__":
  main()

