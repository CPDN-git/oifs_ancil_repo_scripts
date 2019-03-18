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

# setup database connection
db_config='/storage/www/cpdnboinc_alpha/ancil_batch_user_config.xml'
tree=ET.parse(db_config)
db_host=tree.find('db_host').text
db_user=tree.find('db_user').text
db_passwd=tree.find('db_passwd').text
db_name=tree.find('db_name').text
db = MySQLdb.connect(db_host,db_user,db_passwd,db_name,33001)
cursor = db.cursor(MySQLdb.cursors.DictCursor)

def unpack_upload_file(Args):
    dates=[]
    tmp_dir="/storage/www/cpdnboinc_alpha/tmp_ancil_upload/"
    print("Extracting tarfile "+Args.ulfile+" to "+tmp_dir)
    tar= tarfile.open(tmp_dir+Args.ulfile)
    tar.extractall(path=tmp_dir)
    tar.close()
    #tarfile.extract(tmp_dir+Args.ulfile,extract_dir=tmp_dir)
    exptid=Args.ulfile.split('.')[0].split('_')[0]
    rootDir=tmp_dir+exptid

    # make zip files for upload
    zip_dir=tmp_dir+exptid+"_zips/"
    if not os.path.exists(zip_dir):
        os.makedirs(zip_dir)

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
                print("Making zipfile "+zipname)
                shutil.make_archive(zip_dir+zipname,"zip",dirName) 
            else:
                for analysis_no in analysis_nos:
                    zipname="ic_"+exptid+"_"+date+"_"+analysis_no
		    print("Making zipfile "+zipname)
                    shutil.make_archive(zip_dir+zipname,"zip",dirName+"/"+analysis_no) 
                    #output=subprocess.check_output('grib_ls','-w count=1 -p experimentVersionNumber,dataDate,dataTime,M,perturbationNumber ICMSH'+exptid+'INIT') 

def read_grib_keys():
    gKeys=['experimentVersionNumber','dataDate','dataTime','M','perturbationNumber']
    with GribFile(fname,gKeys) as idx:
        dates = idx.values("dataDate") 
        times= idx.values("dataTime")
        hres=idx.values("M")
        pertNo=idx.values("perturbationNumber")
        print(dates, times, hre, pertNo)
        

def add_file(Vars):
    
    if Vars.sub_type=="":
        if Vars.ancil_type=="ic_ancil":
            expt_path=Vars.exptid+"/"+Vars.start_date+Vars.start_time+"/"+Vars.analysis_number+"/"+fname
            url = "http://alpha.cpdn.org/oifs_ancil_files/"+Vars.ancil_type+"/"+expt_path
        else:
            url = "http://alpha.cpdn.orgc/oifs_ancil_files/"+Vars.ancil_type+"/"+fname
    else:
        url = "http://alpha.cpdn.org/oifs_ancil_files/"+Vars.ancil_type+"/"+Vars.sub_type+"/"+fname

    try:
        query= 'insert into oifs_ancil_files (file_name, created_by, uploaded_by, scenario, description, ancil_type, ancil_sub_type, model_version_number, exptid, starting_analysis, analysis_perturbation_number, start_date, start_time, end_date, horizontal_resolution, vertical_resolution, md5sum, url, archive_location) '
        query=query+" values ('"+Vars.file_name+"','"+Vars.created_by+"','"+Vars.uploaded_by+"','"+Vars.scenario+"','"+Vars.description+"','"+Vars.ancil_type+"',"+Vars.sub_type+",'"+Vars.model_version+"','"+Vars.exptid+"','"+Vars.starting_analysis+"','"+Vars.analysis_number+"','"+Vars.start_date+"','"+Vars.start_time+"','"+Vars.end_date+"','"+Vars.horizontal_resolution+"','"+Vars.vertical_resolution+"','"+md5sum+"','"+url+"','"+Vars.archive_location+")"

        print(query)
    #    if Vars.dry_run==False:
    #        cursor.execute(query)
    #        shutil.copyfile(fpath,new_path)
    #        db.commit()
    #    except Exception,e:
    #        print 'Error add file:',fname,e
    #        db.rollback()
    #        continue
    except:
        print("Query error")

#Main controling function
def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("created_by", help="The person who created the file")
    parser.add_argument("uploaded_by", help="The persion who uploaded the file")
    parser.add_argument("model_version", help="The model version number")
    parser.add_argument("scenario", help="The case study description / scenario")
    parser.add_argument("starting_analysis", help="The model starting analysis")
    parser.add_argument("ancil_type", help="The ancillary file type")
    parser.add_argument("sub_type", help="The ancillary file sub-type")
    parser.add_argument("file_desc", help="The file description")
    parser.add_argument("ulfile", help="The upload file")
    args = parser.parse_args()
    unpack_upload_file(args)

    print('Finished!')

#Washerboard function that allows main() to run on running this file
if __name__=="__main__":
  main()

