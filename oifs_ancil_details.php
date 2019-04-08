<?php
require_once("../inc/util.inc");

echo <<<EOH
<html>
<head>
<title>OpenIFS@home Ancil File Details</title>
<META NAME="ROBOTS" CONTENT="NOINDEV, NOFOLLOW">
<script type="text/javascript" src="jquery/jquery-latest.js"></script>
<script type="text/javascript" src="jquery/jquery.tablesorter.js"></script>
<script type="text/javascript">
                        $(document).ready(function() {
                                $("#myTable").tablesorter();
                        });
                </script>
<style type="text/css">
                        #sortedtable thead th {
                                color: #00f;
                                font-weight: bold;
                                text-decoration: underline;
                        }
                </style>
<link rel="stylesheet" href="cpdn.css">
</head>
<body>
EOH;

echo '<div class="wrap">';
echo '<img src="img/logo.png">';
echo '<img src="img/OIFS_Home_logo.png" width="200"></div>';
echo '<hr>';

$xml=simplexml_load_file("/storage/www/cpdnboinc_alpha/ancil_batch_user_config.xml") or die("Error: Cannot create object");
$host= $xml->db_host;
$dbname=$xml->db_name;
$user= $xml->db_user;
$pass= $xml->db_passwd;

$table=$dbname.'.oifs_ancil_files';
$file_name = get_str("file_name");
$fields="file_name, create_time, created_by, description, ancil_type,  ancil_sub_type, model_version_number, exptid, starting_analysis, analysis_perturbation_number, start_date, end_date, spectral_horizontal_resolution, gridpoint_horizontal_resolution, vertical_resolution, batches_used, md5sum, url, status";
$link = mysqli_connect($host,$user,$pass,$dbname) or die("Error " . mysqli_error($link));
$query = $link->prepare("SELECT $fields FROM $table where file_name=?") or die("Error " . mysqli_error($link));
$query->bind_param('s',$file_name);
$query->execute();


# Trying to use bind_result instead of get_result
$query->bind_result($file_name, $create_time, $created_by, $description,  $ancil_type,  $ancil_sub_type, $model_version_number, $exptid, $starting_analysis, $analysis_perturbation_number, $start_date, $end_date, $spectral_horizontal_resolution, $gridpoint_horizontal_resolution, $vertical_resolution, $batches_used, $md5sum, $url, $status);

$query->store_result();

echo "<strong> Ancil File Details</strong> <br>";
echo "<br>";

while ($query->fetch()){
	echo "<strong>Filename: </strong>".$file_name."<br>";
	echo "<strong>Create time: </strong>".$create_time."<br>";
	echo "<strong>Created by: </strong>".$created_by."<br>";
	echo "<strong>Description: </strong>".$description."<br>";
	echo "<strong>Ancil type: </strong>".$ancil_type."<br>";
	echo "<strong>Ancil sub-type: </strong>".$ancil_sub_type."<br>";
	echo "<strong>Model version number: </strong>".$model_version_number."<br>";
	echo "<strong>ECMWF Experiment ID: </strong>".$exptid."<br>";
	echo "<strong>Starting analysis: </strong>".$starting_analysis."<br>";
	echo "<strong>Analysis perturbation number: </strong>".$analysis_perturbation_number."<br>";
	echo "<strong>Start date: </strong>".$start_date."<br>";
	echo "<strong>End date: </strong>".$end_date."<br>";
	echo "<strong>Spectral horizontal resolution: </strong>".$spectral_horizontal_resolution."<br>";
	echo "<strong>Gridpoint horizontal resolution: </strong>".$gridpoint_horizontal_resolution."<br>";
	echo "<strong>Vertical resolution: </strong>".$vertical_resolution."<br>";
	if (!empty($batches_used)){
		$split = explode(",", $batches_used);
        	$out .= implode(", ", $split) . "\r\n";
		echo "<strong>Batches used: </strong>".$out."<br>";
	}
	echo "<strong>url: </strong><a href=".$url.">".$url."</a><br>";
	echo "<strong>Status: </strong>".$status."<br>";
   }

   /* free results */
   $query->free_result();

   /* close statement */
   $query->close();


/* close connection */
#$mysqli->close();

#echo "Past";
#$row=$result->fetch_row();
#$out="";
#foreach(array_keys($fields_array) as $key) {
#	if ($fields_array[$key] == 'batches_used'){
#		$split = explode(",", $row[$key]);
#		$out .= implode(", ", $split) . "\r\n";
#		echo "<strong>".$fields_array[$key].": </strong>".$out."<br>";
#	}
#	else {
#		echo "<strong>".$fields_array[$key].": </strong>".$row[$key]."<br>";
#	}
# }

 ?>
<br><i>Note Status values: 0=valid, 1=replaced, 2=replaced and deprecated, 3=deleted completely, 4=archived/ not available</i>
</body>
</html>
