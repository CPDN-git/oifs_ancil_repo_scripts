#!/usr/bin/env bash
#
#  simple script to deploy required files to user space (html/user)
#


for file in oifs_upload_form.php oifs_upload_handler.php
do
	echo "Copying $file ..."
	cp $file /storage/www/cpdnboinc/html/user
done

