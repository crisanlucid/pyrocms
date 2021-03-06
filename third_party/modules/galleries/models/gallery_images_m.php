<?php if (!defined('BASEPATH')) exit('No direct script access allowed');
/**
 *
 * The galleries module enables users to create albums, upload photos and manage their existing albums.
 *
 * @author 		Yorick Peterse - PyroCMS Dev Team
 * @package 	PyroCMS
 * @subpackage 	Gallery Module
 * @category 	Modules
 * @license 	Apache License v2.0
 */
class Gallery_images_m extends MY_Model
{
	/**
	 * Constructor method
	 * 
	 * @author Yorick Peterse - PyroCMS Dev Team
	 * @access public
	 * @return void
	 */
	public function __construct()
	{
		// Call the parent's constructor
		parent::__construct();
		
		// Load all required classes
		$this->config->load('gallery_config');
		$this->load->library('upload');
		$this->load->library('image_lib');
	}
	
	/**
	 * Get all gallery images along with the gallery slug
	 *
	 * @author Yorick Peterse - PyroCMS Dev Team
	 * @access public
	 * @param int $id The ID of the gallery
	 * @return mixed
	 */
	public function get_images_by_gallery($id)
	{
		$this->db->select('gallery_images.*, galleries.slug, galleries.id as galleries_table_id');
		$this->db->join('galleries', 'gallery_images.gallery_id = galleries.id');
		$query = $this->db->get_where('gallery_images', array('gallery_id' => $id) );
		
		if ( $query->num_rows() > 0 )
		{
			return $query->result();
		}
		else
		{
			return FALSE;
		}
	}
	
	/**
	 * Get an image along with the gallery slug
	 * 
	 * @author Yorick Peterse - PyroCMS Dev Team
	 * @access public
	 * @param int $id The ID of the image
	 * @return mixed
	 */
	public function get_image($id)
	{
		$query = $this->db->select('gallery_images.*, galleries.id as galleries_table_id, galleries.slug')
				 		  ->from('gallery_images')
				 		  ->join('galleries', 'gallery_images.gallery_id = galleries.id')
				    	  ->where('gallery_images.id', $id)
				 		  ->get();
				
		if ( $query->num_rows() > 0 )
		{
			$result = $query->result();
			return $result[0];
		}
		else
		{
			return FALSE;
		}
	}
	
	/**
	 * Upload an image to the server and add it to the DB
	 *
	 * @author Yorick Peterse - PyroCMS Dev Team
	 * @access public
	 * @param array $input The data sent by the form
	 * @return bool
	 */
	public function upload_image($input)
	{
		// Get the name of the gallery we're uploading the image to
		$gallery = $this->db->select('slug')
							->from('galleries')
							->where('id', $input['gallery_id'])
							->get();
		$gallery 		= $gallery->result();
		$gallery_slug 	= $gallery[0]->slug;
		
		// First we need to upload the image to the server
		$upload_conf['upload_path'] 	= 'uploads/galleries/' . $gallery_slug . '/full';
		$upload_conf['allowed_types'] 	= $this->config->item('image_allowed_filetypes');
		$this->upload->initialize($upload_conf);
		
		// Let's see if we can upload the file
		if ( $this->upload->do_upload() )
		{
			$uploaded_data 	= $this->upload->data();
			
			// Set the data for creating a thumbnail
			$source			= 'uploads/galleries/' . $gallery_slug . '/full/' . $uploaded_data['file_name'];
			$destination	= 'uploads/galleries/' . $gallery_slug . '/thumbs';
			$options		= array();
			
			// Is the current size larger? If so, resize to a width/height of X pixels (determined by the config file)
			if ( $uploaded_data['image_width'] > $this->config->item('image_thumb_width'))
			{
				$options['width'] = $this->config->item('image_thumb_width');
			}
			if ( $uploaded_data['image_height'] > $this->config->item('image_thumb_height'))
			{
				$options['height'] = $this->config->item('image_thumb_height');
			}
			
			// Great, time to create a thumbnail
			if ( $this->create_thumbnail('resize', $source, $destination, $options) === TRUE )
			{
				// Image has been uploaded, thumbnail has been created, time to add it to the DB!
				$file 					 = split('\.', $uploaded_data['file_name']);
				$to_insert['gallery_id'] = $input['gallery_id'];
				$to_insert['filename']	 = $file[0];
				$to_insert['extension']	 = $file[1];
				$to_insert['title']		 = $input['title'];
				$to_insert['description']= $input['description'];
				$to_insert['uploaded_on']= time();
				$to_insert['updated_on'] = time();
				
				// Insert it
				if ( is_int(parent::insert($to_insert)) )
				{
					return TRUE;
				}
				else
				{
					return FALSE;
				}
			}
		}	
	}
	
	/**
	 * Update an existing image
	 *
	 * @author Yorick Peterse - PyroCMS Dev Team
	 * @access public
	 * @param int $id The ID of the image
	 * @param array $input The data used for updating the image
	 * @return mixed
	 */
	public function update_image($id, $input)
	{
		// Get the data related to the image we're working with
		$image = $this->db->from('gallery_images')
						  ->join('galleries', 'gallery_images.gallery_id = galleries.id')
						  ->where('gallery_images.id', $id)
						  ->get();
		$image = $image->result();
		$image = $image[0];
		// Set the paths
		$full_path 	= 'uploads/galleries/' . $image->slug . '/full/' 	. $image->filename 	. '.' 		. $image->extension;	
		$thumb_path = 'uploads/galleries/' . $image->slug . '/thumbs/' 	. $image->filename 	. '_thumb.' . $image->extension;
		
		// Crop an existing thumbnail
		if ( $input['thumbnail_actions'] === 'crop' )
		{
			// Get the required values for cropping the thumbnail
			$options['width'] 		= $input['thumb_width'];
			$options['height']		= $input['thumb_height'];
			$options['x_axis']		= $input['thumb_x'];
			$options['y_axis']		= $input['thumb_y'];
			$options['create_thumb']	= FALSE;
			$options['maintain_ratio']	= FALSE;
			
			// Crop the thumbnail
			if ( $this->create_thumbnail('crop', $full_path, $thumb_path, $options) !== TRUE)
			{
				return FALSE;
			}
		} 
		
		// Create a new thumbnail
		else if ( $input['thumbnail_actions'] === 'new' )
		{
			// We need to figure out if the source file is larger than the dimensions defined in the config file
			$size_array = getimagesize($full_path);
			$width 		= $size_array[0];
			$height 	= $size_array[1];
			$options	= array();
			
			// Is the current size larger? If so, resize to a width/height of X pixels (determined by the config file)
			if ( $width > $this->config->item('image_thumb_width'))
			{
				$options['width'] = $this->config->item('image_thumb_width');
			}
			if ( $height > $this->config->item('image_thumb_height'))
			{
				$options['height'] = $this->config->item('image_thumb_height');
			}
			
			// Create the new thumbnail
			if ( $this->create_thumbnail('resize', $full_path, 'uploads/galleries/' . $image->slug . '/thumbs/', $options) !== TRUE )
			{
				return FALSE;
			}
		}
		
		// Delete the image from the DB and the filesystem
		else if ( $input['thumbnail_actions'] === 'delete' )
		{
			// First we'll delete it from the DB
			if ( parent::delete($id) )
			{	
				// Change the table
				$this->table = 'galleries';
						
				// Unset the thumbnail for each gallery that was using this image
				if ( parent::update_by('thumbnail_id', $id, array('thumbnail_id' => NULL)) )
				{
					// Change the table back
					$this->table = 'gallery_images';
					
					// Delete the files
					if ( unlink($full_path) === TRUE AND unlink($thumb_path) === TRUE )
					{
						return TRUE;
					}
					else
					{
						return FALSE;
					}
				}
				else
				{
					return FALSE;
				}
			}
			else
			{
				return FALSE;
			}
		}
		
		// Just save it already, do note that data isn't saved if the user decides to delete an image
		$to_update['title'] 		= $input['title'];
		$to_update['description'] 	= $input['description'];
		$to_update['updated_on']	= time();
		
		return parent::update($id, $to_update);
	}
	
	/**
	 * HELPER METHODS
	 * 
	 * The methods below perform tasks such as resizing thumbnails, counting photos, etc
	 */
	
	/**
	 * Create a thumbnail
	 *
	 * @author Yorick Peterse - PyroCMS Dev Team
	 * @access public
	 * @param string $mode The mode of image manipulation, either "resize" or "crop"
	 * @param string $source The image to use for creating the thumbnail
	 * @param string $destination The location of the new file
	 * @param array $options Optional array that may contain data such as the new width, height, etc
	 * @return bool
	 */
	public function create_thumbnail($mode, $source, $destination, $options = array())
	{		
		// Time to resize the thumbnail
		$image_conf['image_library'] 	= 'gd2';
		$image_conf['source_image']  	= $source;
		
		// Save a new image somewhere else?
		if ( !empty($destination) )
		{
			$image_conf['new_image']	= $destination;
		}
		
		$image_conf['thumb_marker']		= '_thumb';
		$image_conf['create_thumb']  	= TRUE; 
		$image_conf['quality']			= '100';
		
		// Optional parameters set?
		if ( !empty($options) )
		{
			// Loop through each option and add it to the $image_conf array
			foreach ( $options as $key => $option )
			{
				$image_conf[$key] = $option;
			}
		}
		
		// If the thumbnail already exists, delete it
		if ( is_file($destination) )
		{
			unlink($destination);
		}
		
		$this->image_lib->initialize($image_conf);
		
		if ( $mode == 'resize' )
		{
			return $this->image_lib->resize();
		}
		else if ( $mode == 'crop' )
		{
			return $this->image_lib->crop();
		}
		
		return FALSE;
	}
}