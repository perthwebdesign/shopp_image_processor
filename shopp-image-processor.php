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

		var $ImageKey = "sku_";


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
			
			$ImageParts = explode("_", $UniqueProductIdentifier);
			$Sku = $ImageParts[0];
			$ImageNumber = $ImageParts[1];
			
			$Query = $this['db']->createQueryBuilder();
		
			$Query->select("*")
				->from("{$this->tablePrefix}shopp_meta", "meta")
				->leftjoin("meta","{$this->tablePrefix}shopp_product","product","product.id = meta.parent")
				->where("meta.name = 'sku'")
				->andwhere("meta.value LIKE '%{$Sku}%'")
			;
			
			$Product = $Query->execute()->fetch();

			echo "$Sku <br />";
			
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
		function PrepareImage( $Filepath ) {
			
			$ImageAttributes = getimagesize( $Filepath );
			
			$Filename = str_replace($this->imageDirectory, "", $Filepath);
			
			$Image = (object) array(
				'filename'	=> $Filename,
				'path'		=> $Filepath,
				'width'		=> strval( $ImageAttributes[0] ),
				'height'	=> strval( $ImageAttributes[1] ),
				'mime'		=> $ImageAttributes['mime'],
				'filesize'	=> strval( filesize( $Filepath ) ),
			);
			
			return $Image;
		}
		
		function addImageToMetaTable( $Attributes ) {
			$this['db']->insert( "{$this->tablePrefix}shopp_meta", $Attributes);
		}
		
		
		
		function GetAllProduct() {
			$Query = $this['db']->createQueryBuilder();
			$Query->select("*")
				->from("{$this->tablePrefix}shopp_product", "product")
			;
			
			return $Query->execute()->fetchAll();
		}
		
		function GetProductMeta( $productID ) {
			$Query = $this['db']->createQueryBuilder();
			$Query->select("*")
				->from("{$this->tablePrefix}shopp_meta", "meta")
				->where("meta.parent = '{$productID}'")
			;
				
			return $Query->execute()->fetchAll();
		}
		function GetProductMetaSku( $productID ) {
			$Query = $this['db']->createQueryBuilder();
			$Query->select("*")
				->from("{$this->tablePrefix}shopp_meta", "meta")
				->where("meta.parent = '{$productID}'")
				->andwhere("meta.name = 'sku'")
			;
			$ProductMeta = $Query->execute()->fetch();
			return $ProductMeta['value'];
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
		
		//.. fetch all the uploaded images.
		$Images = $app->fetchUploadedImages();
		
		//.. get all products.
		foreach( $app->GetAllProduct() as $Product ) {
			
			//.. Grab product sku.
			$FullProductSku = $app->GetProductMetaSku( $Product['id'] );
			$ProductSku = explode("-", $FullProductSku);
			$ProductSku = $ProductSku[0];
			
			echo "<h1>This is the Full Product SKU $FullProductSku</h1>";
			echo "<h2>This is the small Product SKU $ProductSku</h2>";
			
			//.. INITIAL CHECK
			$ImagePathMatches = glob($app->imageDirectory . "sku_{$FullProductSku}*");
			if( count( $ImagePathMatches ) == 0 ) {
				//.. SECONDARY CHECK
				$ImagePathMatches = glob($app->imageDirectory . "sku_{$ProductSku}*");
			}
			
			//.. Flip array so the straight product sku matches are added first.
			$ImagePathMatches = array_reverse($ImagePathMatches);
			
			foreach( $ImagePathMatches as $Filename ) {
				$Image = $app->PrepareImage( $Filename );
				
				$ImageObject = (object) array(
					"mime"		=> $Image->mime,
					"size"		=> $Image->filesize,	
					"storage"	=> "FSStorage",
					"uri"		=> $Image->filename,
					"filename"	=> $Image->filename,
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
			
		};
	});


	//.. Very Basic route setup. Only run on http://...../processimages/
	if ( $_SERVER['REQUEST_URI'] == "/processimages/" ) {
		$app->run();
	}

