<?php
$project_path="/storage/www/cpdnboinc_alpha/";
$xml=simplexml_load_file($project_path."ancil_batch_user_config.xml") or die("Error: Cannot create object");
$host= $xml->db_host;
$dbname=$xml->db_name;
$boinc_dbname=$xml->boinc_db_name;
$user= $xml->db_user;
$pass= $xml->db_passwd;

# include the table tag generatora 
require_once('includes/html_table.class.php');

require_once("../inc/util.inc");

//page_head("OpenIFS Ancil Search");


echo <<<EOH
<html>
<head>
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


?>
    <form action="" method="post">
    <table width="100%" border="0" style="border:none;">
      <tr>
        <td><label>File Name:<br></label><input type="text" name="by_name" /></td>
        <td><label>Case Study/Description:<br></label><input type="text" name="by_desc" /></td>
	<td><label>Type:<br></label><input type="text" name="by_type" /></td>
	<td><label>Sub type:<br></label><input type="text" name="by_subtype" /></td>
        <td><label>Start Date:<br></label><input type="text" name="by_start" /></td>
        <td><label>ECMWF exptid:<br></label><input type="text" name="by_exptid" /></td>
        <td><input class="button" type="submit" name="submit" value="Search" /></td>
      </tr>
    </table>
    </form>
<?php

if(isset($_POST['submit'])) {
    $by_name = $_POST['by_name'];
    $by_desc = $_POST['by_desc'];
    $by_type = $_POST['by_type'];
    $by_subtype = $_POST['by_subtype'];
    $by_start = $_POST['by_start'];
    $by_exptid = $_POST['by_exptid'];
    //Do real escaping here

    $query = "SELECT CONCAT('<a href=http://alpha.cpdn.org/oifs_ancil_details.php?file_name=',file_name,'>',file_name,'</a>') as 'File name', date(create_time) as 'Creation date', created_by as 'Created by', description as 'Description', ancil_type as 'Type', IF(STRCMP(ancil_sub_type,'0') = 0,'',ancil_sub_type) as 'Sub type', model_version_number as 'Model version', exptid as 'Exptid', starting_analysis as 'Starting analysis', analysis_perturbation_number as 'Analysis number', start_date as 'Start date', end_date as 'End date', CONCAT(spectral_horizontal_resolution,gridpoint_horizontal_resolution) as 'Horizontal resolution', vertical_resolution as 'Vertical resolution', batches_used as 'Batches used'  FROM ".$dbname.".oifs_ancil_files";
    $conditions = array();

    if(! empty($by_name)) {
      $conditions[] = "file_name LIKE '%$by_name%'";
    }
    if(! empty($by_desc)) {
      $conditions[] = "description LIKE '%$by_desc%'";
    }
    if(! empty($by_type)) {
      $conditions[] = "ancil_type='$by_type'";
    }
    if(! empty($by_subtype)) {
      $conditions[] = "ancil_sub_type='$by_subtype'";
    }
    if(! empty($by_start)) {
      $conditions[] = "start_date='$by_start'";
    }
    if(! empty($by_exptid)) {
      $conditions[] = "exptid='$by_exptid'";
    }
    $sql = $query;
    if (count($conditions) > 0) {
      $sql .= " WHERE " . implode(' AND ', $conditions);
    }

    $tablesorter_class='cpdnTable';

    $link = mysqli_connect($host,$user,$pass,$dbname) or die("Error " . mysqli_error($link));
    $result = $link->query($sql) or die("Error in the consult.." . mysqli_error($link));

class Auto_Table extends HTML_table	{
	private $db_result = NULL;
	private $script_tags =array();
	private $style_tags =array();
	
function make_script($script='script', $content='', $attrs=array()){
	array_push ($this->script_tags, $this->make_tag($script, $content, $attrs));
	
	}

function display_script(){
	$mystr='';
	foreach ($this->script_tags as $tag){
		$mystr.= "$tag\n";
		}
	return $mystr;
	}

function make_css($tag){
	}

function make_tag($tag, $content='', $attrs=array()){
	# void tags have no content, and must be closed with />
	$html_void= array("area", "base", "br", "col", "command", "embed", "hr", "img", "input", "keygen", "link", "meta", "param", "source", "track", "wbr");
	$mytag =''; 
	$mytag ='<'.$tag;
	if (in_array($tag, $html_void)){
		$mytag .= ' />';
		}
	else {
		$mytag .= ">$content</$tag>";
		}
	return $mytag;
	}
	
	
function make_table($db_result){
	$this->db_result = $db_result;
	$this->addTSection('thead');
	$this->addRow();
	$finfo = $this->db_result->fetch_fields();
		 foreach (array_slice($finfo,0,-1) as $f){
			$this->addCell($f->name, '', 'header');
			}
	$this->addTSection('tbody');
	while ($row = $db_result->fetch_row()) {
		$this->addRow();
		foreach (array_slice($row,0,-1) as $cell){
			if (end($row) == 0) {
				$this->addCell($cell,'','data',array('class'=>$row[4]));
			}
			else {
				$this->addCell($cell,'','data',array('class'=>$row[4],'bgcolor'=>'F5F5F5'));
			}
			}
		}
	}
}

    $row_cnt = $result->num_rows;
    if ($row_cnt > 0)
    {

        $tbl = new Auto_Table('myTable', 'tablesorter');
        #$tbl->addCaption($query, 'cap', array('id'=> 'tblCap') );

        $tbl->make_table($result);
        $tbl->make_script('script',' ',array('src' => "jquery/jquery-latest.js"));
        echo $tbl->display_script();
        echo $tbl->display();
    }
    mysqli_free_result($result);
    mysqli_close ($link);

}
?>
