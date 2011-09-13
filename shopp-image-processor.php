<?php
/*
Plugin Name: Shopp Image Processor
Plugin URI: http://www.pwd.net.au
Description: Processes uploaded images into Shopp 1.1.9 format (Slapped together)
Author: Matt Boddy
Version: 0.1
Last Updated: 13/09/2011
Author URI: http://www.pwd.net.au
*/

require_once __DIR__.'/silex.phar';

	/**
	 * 
	 */
	class ShoppImageProcessor extends Silex\Application {

		var $ImageKey = "sku--";


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
		
		function GetProductImageNamesById( $ProductId ) {
			$Query = $this['db']->createQueryBuilder();
		
			$Query->select("*")
				->from("{$this->tablePrefix}shopp_meta", "meta")
				->leftjoin("meta","{$this->tablePrefix}shopp_product","product","product.id = meta.parent")
				->where("meta.parent = $ProductId")
				->andwhere("meta.type = 'image'")
				->andwhere("meta.name = 'original'")
			;
			
			$ProductMetas = $Query->execute()->fetchAll();
			
			$CurrentProductImageFilenames = array();
			foreach( $ProductMetas as $ProductImageMeta ) {
				$MetaArray = unserialize($ProductImageMeta['value']);
				$CurrentProductImageFilenames[] = $MetaArray->filename; 
			}
	
			return $CurrentProductImageFilenames;
		}
		
		function matchProduct( $UniqueProductIdentifier ) {
			
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
			
			$ImagePaths = glob($this->imageDirectory . "{$this->ImageKey}*");
			$ImagePaths = str_replace("{$this->imageDirectory}", "", $ImagePaths);
			
			//.. return images that don't have cache_ in the filename
			return $ImagePaths;
		}
		
		//.. Creates an image object
		function PrepareImage( $Filename ) {
			
			//.. try to determine the SKU to match image to
			preg_match("/sku--([A-z0-9]*)?/", $Filename, $Matches);
			$sku = $Matches[1];
			
			$ImageAttributes = getimagesize( $this->imageDirectory . $Filename );
			
			$Image = (object) array(
				'sku' 		=> $sku,
				'filename'	=> $Filename,
				'path'		=> $this->imageDirectory . $Filename,
				'width'		=> strval( $ImageAttributes[0] ),
				'height'	=> strval( $ImageAttributes[1] ),
				'mime'		=> $ImageDetails['mime'],
				'filesize'	=> strval( filesize( $this->imageDirectory . $Filename ) ),
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
		
		foreach( $app->fetchUploadedImages() as $FileName ) {
			
			//.. new image object (from filesystem)
			$Image = $app->PrepareImage( $FileName );
			
			//.. new product array (from database)
			$Product = $app->matchProduct( $Image->sku );
			
			//.. if a product is found. Prepare image to be inserted into the meta table 
			if( $Product) {
				
				$ImageObject = (object) array(
					"mime"		=> $Image->mime,
					"size"		=> $Image->filesize,	
					"storage"	=> "FSStorage",
					"uri"		=> $FileName,
					"filename"	=> $FileName,
					"width"		=> $Image->width,
					"height"	=> $Image->height,
					"alt"		=> "",
					"title"		=> "",
					"settings"	=> "",
				);
				
				$ImageMeta = array(
					'parent'	=> $Product['id'],
					'context'	=> 'product',
					'type'		=> 'image',
					'name'		=> 'original',
					'value'		=> serialize( $ImageObject ),
					'numeral'	=> 0,
					'sortorder'	=> 0,
					'created'	=> $app->dateTime(),
					'modified'	=> $app->dateTime(),
				);
				
				//.. Make sure an instance of the image doesn't already exist in the meta table
				if( !in_array( $Image->filename, $app->GetProductImageNamesById( $Product['id'] ) ) ) {
					$app->addImageToMetaTable($ImageMeta);
				}
			}
		}
	});


	//.. Very Basic route setup. Only run on http://...../processimages/
	if ( $_SERVER['REQUEST_URI'] == "/processimages/" ) {
		$app->run();
	}

