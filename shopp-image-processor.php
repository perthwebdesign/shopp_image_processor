<?php
/*
Plugin Name: Shopp Image Processor
Plugin URI: http://www.pwd.net.au
Description: Processes uploaded images into Shopp 1.1.9 format
Author: Matt
Version: 0.1
Last Updated: 13/09/2011
Author URI: http://www.pwd.net.au
*/

require_once __DIR__.'/silex.phar';

	/**
	 * 
	 */
	class ShoppImageProcessor extends Silex\Application {

		var $tablePrefix = null;
		var $imageDirectory = null;
		
		function __construct() {
			parent::__construct();
			
			$this->setTablePrefix();
			$this->setUploadDirectory();
			
		}
		
		function dateTime() {
			return date('Y-m-d H:i:s');
		}
		
		private function setTablePrefix() {
			global $wpdb;
			$this->tablePrefix = $wpdb->prefix;
		}
		
		private function setUploadDirectory() {
			$upload_dir = wp_upload_dir();
			$this->imageDirectory = $upload_dir['path'] . "/shopp_images/";
		}
		
		function matchProduct( $UniqueProductIdentifier ) {
			
			echo "<pre>";
			var_dump($UniqueProductIdentifier);
			echo "</pre>";
			
			$ImageParts = explode("-", $UniqueProductIdentifier);
			
			$Sku = $ImageParts[0];
			$ImageNumber = $ImageParts[1];
			
			$Query = $this['db']->createQueryBuilder();
		
			$Query->select("*")
				->from("{$this->tablePrefix}shopp_meta", "meta")
				->leftjoin("meta","{$this->tablePrefix}shopp_product","product","product.id = meta.parent")
				->where("meta.name = 'sku'")
				->andwhere("meta.value = '$Sku'")
			;
			
			$Product = $Query->execute()->fetch();

			if( count( $Product ) > 0 ) {
				return $Product;
			} else {
				return false;
			}
		}
		
		function checkImageInMetaTable( $ProductID ) {
			
			$Query = $this['db']->createQueryBuilder();
		
			$Query->select("*")
				->from("{$this->tablePrefix}shopp_meta", "meta")
				->leftjoin("meta","{$this->tablePrefix}shopp_product","product","product.id = meta.parent")
				->where("meta.type = 'image'")
				->andwhere("meta.name = 'original'")
				->andwhere("meta.parent = '$ProductID'")
			;
			
			$Image = $Query->execute()->fetch();

			if( count( $Image ) > 0 ) {
				return $Image;
			} else {
				return false;
			}
		}
		
		//.. return uploaded images as an array
		function fetchUploadedImages() {
			global $Shopp;
			
			$ImagePaths = glob($this->imageDirectory . "[!cache_|!Thumbs]*");
			$ImagePaths = str_replace("{$this->imageDirectory}", "", $ImagePaths);
			
			//.. return images that don't have cache_ in the filename
			return $ImagePaths;
		}
		
		function getImageDetails( $ImagePath ) {
			
			$ImageDetails = getimagesize( $ImagePath );
			$Image = array(
				'width'		=> strval( $ImageDetails[0] ),
				'height'	=> strval( $ImageDetails[1] ),
				'mime'		=> $ImageDetails['mime'],
				'filesize'	=> strval( filesize( $ImagePath ) ),
			);
			
			return $Image;
		}
		
		function addImageToMetaTable( $Attributes ) {
			$this['db']->insert( "{$this->tablePrefix}shopp_meta", $Attributes);
		}
		
		
	}
	

	$app = new ShoppImageProcessor;
	$app['debug'] = true;
	$app->register(new Silex\Extension\SessionExtension());
	$app->register(new Silex\Extension\DoctrineExtension(), array(
	    'db.options' => array (
	        'driver'    => 'pdo_mysql',
	        'host'      => DB_HOST,
	        'dbname'    => DB_NAME,
	        'user'      => DB_USER,
	        'password'  => DB_PASSWORD,
	    ),
	    'db.dbal.class_path'    => __DIR__.'/vendor/doctrine-dbal/lib',
	    'db.common.class_path'  => __DIR__.'/vendor/doctrine-common/lib',
	));
	
	use Symfony\Component\HttpFoundation\Response;
	
	$app->match('/processimages/', function () use ($app) {
		
		$MatchCount = 1;
		foreach( $app->fetchUploadedImages() as $FileName ) {
			preg_match("/(.*)\..*$/", $FileName, $Matches);
			
			$ImageName = $Matches[1];
			
			$MatchedProduct = $app->matchProduct( $ImageName );
			
			if( $MatchedProduct) {
				
				$ImageDetails = $app->getImageDetails( $app->imageDirectory . $FileName );
				
				$ImageObject = (object) array(
					"mime"		=> $ImageDetails['mime'],
					"size"		=> $ImageDetails['filesize'],	
					"storage"	=> "FSStorage",
					"uri"		=> $FileName,
					"filename"	=> $FileName,
					"width"		=> $ImageDetails['width'],
					"height"	=> $ImageDetails['height'],
					"alt"		=> "",
					"title"		=> "",
					"settings"	=> "",
				);
				
				$ImageMeta = array(
						'parent'	=> $MatchedProduct['id'],
						'context'	=> 'product',
						'type'		=> 'image',
						'name'		=> 'original',
						'value'		=> serialize( $ImageObject ),
						'numeral'	=> 0,
						'sortorder'	=> 0,
						'created'	=> $app->dateTime(),
						'modified'	=> $app->dateTime(),
				);
				
				if( !$app->checkImageInMetaTable( $MatchedProduct['id'] ) ) {
					$app->addImageToMetaTable($ImageMeta);
					
				}
			
			}
			$MatchCount = $MatchCount + 1;
		}
		echo $MatchCount;
	});

	if ( $_SERVER['REQUEST_URI'] == "/processimages/" ) {
		$app->run();
	}

