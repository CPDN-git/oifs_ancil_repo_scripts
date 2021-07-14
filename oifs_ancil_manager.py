#!/usr/bin/python2.7
import MySQLdb
import os
import time
import xml.etree.ElementTree as ET


site="dev"

# setup database connection
db_config='/storage/www/cpdnboinc_'+site+'/ancil_batch_user_config.xml'
tree=ET.parse(db_config)
db_host=tree.find('ancil_db_host').text
db_user=tree.find('ancil_user').text
db_passwd=tree.find('ancil_passwd').text
db_name=tree.find('ancil_db_name').text
db = MySQLdb.connect(db_host,db_user,db_passwd,db_name)
cursor = db.cursor(MySQLdb.cursors.DictCursor)

basepath="/storage/cpdn_ancil_files/oifs_ancil_files/"

def set_replaced(filename,replaced_by,dry_run=False):
    query='select file_name,url from oifs_ancil_files where file_name like "'+filename+'" and status<3'
    cursor.execute(query)
    if dry_run:
        print("Dry Run")
    else:
        prin("Setting replaced by for ancils:")
        flog=open('replaced_ancils.log','a')
        flog.write(time.ctime()+'\n')
    for row in cursor.fetchall():
        print(row['file_name'])
        query2="update oifs_ancil_files set status=1,replaced_by='"+replaced_by+"' where file_name='"+row['file_name']+"'"
        if not dry_run:
            cursor.execute(query2)
            db.commit()
            flog.write(row['file_name']+'\n')
            
def deprecate(filename,replaced_by=None,dry_run=False):
    query='select file_name,url from oifs_ancil_files where file_name like "'+filename+'" and status<3'
    cursor.execute(query)
    if dry_run:
        print("Dry Run")
    else:
        print("Deprecating ancils:")
        flog=open('deprecate_and_delete.log','a')
        flog.write(time.ctime()+'\n')
    for row in cursor.fetchall():
        print(row['file_name'])
        if replaced_by:
            replace_string = ",replaced_by='"+replaced_by+"'"
        else:
            replace_string=''
        query2="update oifs_ancil_files set status=2"+replace_string+" where file_name='"+row['file_name']+"'"
        if not dry_run:
            cursor.execute(query2)
            db.commit()
            flog.write(row['file_name']+'\n')

def deprecate_and_delete(filename,replaced_by=None,dry_run=False):
    query='select file_name,url from oifs_ancil_files where file_name like "'+filename+'" and status<3'
    cursor.execute(query)
    if dry_run:
        print("Dry Run")
    else:
        print("Deprecating and deleting ancils:")
        flog=open('deprecate_and_delete.log','a')
        flog.write(time.ctime()+'\n')
    for row in cursor.fetchall():
        print(row['file_name'])
        if replaced_by:
            replace_string = ",replaced_by='"+replaced_by+"'"
        else:
            replace_string=''
        query2="update oifs_ancil_files set url='',status=3"+replace_string+" where file_name='"+row['file_name']+"'"
        filepath=basepath+row['url'].split('http://dev.cpdn.org/oifs_ancil_files/')[1]
        if not dry_run:
            cursor.execute(query2)
            os.remove(filepath)
            db.commit()
            flog.write(row['file_name']+'\n')

def delete_completely(filename,dry_run=False):
        basepath="/storage/cpdn_ancil_files/oifs_ancil_files"
        query='select file_name,url from oifs_ancil_files where file_name like "'+filename+'"'
        cursor.execute(query)
        if dry_run:
                print("Dry Run")
        else:
                print("Deleting ancils completely:")
                flog=open('delete_completely.log','a')
                flog.write(time.ctime()+'\n')
        for row in cursor.fetchall():
                print(row['file_name'])
                query2="delete from oifs_ancil_files where file_name='"+row['file_name']+"'"
                print(row['url'])
                filepath=basepath+row['url'].split('http://dev.cpdn.org/oifs_ancil_files')[1]
                print('filepath',filepath)
                if not dry_run:
                        cursor.execute(query2)
                        os.remove(filepath)
                        db.commit()
                        flog.write(row['file_name']+'\n')
    
if __name__=='__main__':
    delete_completely("ic_hh58_2015120300_00.zip",dry_run=False)
