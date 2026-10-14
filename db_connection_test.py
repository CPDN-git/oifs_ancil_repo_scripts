#!/usr/bin/python

import pymysql
import xml.etree.ElementTree as ET

site='dev'

# setup database connection
db_config='/storage/www/cpdnboinc_'+site+'/ancil_batch_user_config.xml'
tree=ET.parse(db_config)
db_host=tree.find('ancil_db_host').text
db_local_host=tree.find('ancil_local_db_host').text
db_user=tree.find('ancil_user').text
db_passwd=tree.find('ancil_passwd').text
db_name=tree.find('ancil_db_name').text

#db = pymysql.connect(host=db_host,user=db_user,password=db_passwd,database=db_name,unix_socket="/storage/mysql/mysql.sock")
db = pymysql.connect(host=db_host,user=db_user,password=db_passwd)#,unix_socket="/storage/mysql/mysql.sock")
cursor = db.cursor(pymysql.cursors.DictCursor)
query='select * from '+db_name+'.oifs_ancil_files where ancil_type="climate_data"'
cursor.execute(query)

print("Success!")
