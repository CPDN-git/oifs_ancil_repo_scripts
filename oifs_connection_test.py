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
db_host=tree.find('ancil_db_host').text
db_user=tree.find('ancil_user').text
db_passwd=tree.find('ancil_passwd').text
db_name=tree.find('ancil_db_name').text
db = MySQLdb.connect(db_host,db_user,db_passwd,db_name)
cursor = db.cursor(MySQLdb.cursors.DictCursor)

#Main controling function
def main():
    
    print('Finished!')

#Washerboard function that allows main() to run on running this file
if __name__=="__main__":
  main()

