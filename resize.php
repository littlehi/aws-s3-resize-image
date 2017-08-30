<?php
date_default_timezone_set('Asia/shanghai');
require 'vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

$bucketName='images.littlehi.com';

$notFoundImg='404.jpg';

$src=$_GET['src'];

if(preg_match("/^(.*)_(\d+)x(\d+).([a-z]+)$/",$src,$matches)){

$objectName=$matches[1].'.'.$matches[4];
$width=$matches[2];
$height=$matches[3];


$client = new S3Client([ 'region' => 'cn-north-1', 'version' => 'latest']);
$client->registerStreamWrapper();

try{
  $imagick = new \Imagick('s3://'.$bucketName.'/'.$objectName);
}
catch (Exception $e) {
  $imagick= new \Imagick($notFoundImg);
}

if($imagick){
  $contentType=$imagick->getImageMimeType();

  $imagick->scaleImage($width,$height);

  header("Content-type: ".$contentType);
  echo $imagick->getImageBlob();

  $client->putObject([
    'ContentType' => '$contentType',
    'Body' =>$imagick->getImageBlob(),
    'Bucket' => $bucketName,
    'Key' => $src,
    'StorageClass' => 'REDUCED_REDUNDANCY',
    'Tagging' => 'thumbnail=yes'
  ]);
  die();
}



}

header("Content-type: image/jpeg");
echo file_get_contents($notFoundImg);