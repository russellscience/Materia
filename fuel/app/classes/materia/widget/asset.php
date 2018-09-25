<?php
/**
 * NEEDS DOCUMENTATION
 *
 * The widget managers for the Materia package.
 *
 * @package	    Main
 * @subpackage  asset * @author      ADD NAME HERE
 */

namespace Materia;

class Widget_Asset
{

	const MAP_TYPE_QSET     = '1';
	const MAP_TYPE_QUESTION = '2';

	public $created_at = 0;
	public $id         = 0;
	public $is_shared;
	public $title      = '';
	public $file_size  = '';
	public $remote_url = null;
	public $status     = null;
	public $questions  = [];
	public $type       = '';
	protected $_storage_driver;

	public function __construct($properties=[])
	{
		$this->set_properties($properties);
		$use_s3 = \Config::get('materia.s3_config.s3_enabled') === true;
		$storage_class = $use_s3 ? '\Materia\Widget_Asset_S3storage' : '\Materia\Widget_Asset_Dbstorage';
		$this->_storage_driver = call_user_func($storage_class.'::instance');
	}

	/**
	 * Search and fetch the database for a Widget_Asset by it's ID
	 * @param  string $id ID of the Widget_Asset
	 * @return Widget_Asset
	 */
	static public function fetch_by_id(string $id): Widget_Asset
	{
		$asset = new Widget_Asset();
		$asset->db_get($id);
		return $asset;
	}

	public function set_properties($properties=[])
	{
		if ( ! empty($properties))
		{
			foreach ($properties as $key => $val)
			{
				if (property_exists($this, $key)) $this->{$key} = $val;
			}

			$this->type = strtolower($this->type);

			// give all jpg images a consistent extension
			if ($this->type == 'jpeg')
			{
				$this->type = 'jpg';
			}
		}
	}

	/**
	 * NEEDS DOCUMENTATION
	 *
	 * @param The database manager
	 */
	public function db_update()
	{
		if (empty($this->type)) return false;

		\DB::start_transaction();

		try
		{
			// ensure user has permission to update this asset
			$q = \DB::select()
				->from('perm_object_to_user')
				->where('object_id', $this->id)
				->and_where('user_id', \Model_User::find_current_id())
				->execute();

			// user should only own one object with this id
			if (count($q) == 1)
			{
				$tr = \DB::update('asset')
					->set([
						'type'        => $this->type,
						'title'       => $this->title,
						'file_size'   => $this->file_size,
						'remote_url'  => $this->remote_url,
						'status'      => $this->status,
						'created_at'  => time()
					])
					->where('id','=',$this->id)
					->execute();

				if ($tr == 1) // ensure only one asset is updated
				{
					\DB::commit_transaction();
					return true;
				}
				else
				{
					\LOG::error('Multiple assets exist with the same id: '.$this->id.'. None of these assets could be updated.');
					return false;
				}
			}
			else
			{
				\LOG::error('User id '.\Model_User::find_current_id().'owns zero or more than one object with the id: '.$this->id.'. Asset could not be updated.');
				return false;
			}
		}
		catch (Exception $e)
		{
			\DB::rollback_transaction();
			\LOG::error('The following exception occured while attempting to update asset id, '.$this->id.', for user id,'.\Model_User::find_current_id().': '.$e);
			return false;
		}
	}

	/**
	 * Finds an available asset id
	 * to avoid conflicts in the db
	 */
	protected function get_unused_id()
	{
		// try finding an id not used in the database
		$max_tries = 10;
		for ($i = 0; $i <= $max_tries; $i++)
		{
			$asset_id = Widget_Instance_Hash::generate_key_hash();
			$asset_exists = $this->db_get($asset_id);
			if ( ! $asset_exists)
			{
				break;
			}
		}
		// all ids that were searched already exist
		if ($asset_exists)
		{
			return null;
		}

		return $asset_id;
	}

	/**
	 * NEEDS DOCUMENTATION
	 *
	 * @param The database manager
	 */
	public function db_store()
	{
		if (\Materia\Util_Validator::is_valid_hash($this->id) && empty($this->type)) return false;

		$asset_id = $this->get_unused_id();
		if (empty($asset_id))
		{
			return false;
		}

		// if this asset has a remote_url stub, append the
		// id. otherwise, leave it null
		if (isset($this->remote_url))
		{
			// used to identify who uploaded asset
			$user_id = \Model_User::find_current_id();

			// Builds remote_url
			$this->remote_url .= "{$user_id}-{$asset_id}.{$this->type}";
		}

		\DB::start_transaction();

		try
		{
			$tr = \DB::insert('asset')
				->set([
					'id'          => $asset_id,
					'type'        => $this->type,
					'title'       => $this->title,
					'file_size'   => $this->file_size,
					'remote_url'  => $this->remote_url,
					'status'      => $this->status,
					'created_at'  => time()
				])
				->execute();

			$q = \DB::insert('perm_object_to_user')
				->set([
					'object_id'   => $asset_id,
					'user_id'     => \Model_User::find_current_id(),
					'perm'        => Perm::FULL,
					'object_type' => Perm::ASSET
				])
				->execute();

			if ($tr[1] > 0)
			{
				$this->id = $asset_id;
				\DB::commit_transaction();
				return true;
			}

		}
		catch (Exception $e)
		{
			\LOG::error('The following exception occured while attempting to store and asset for user id,'.\Model_User::find_current_id().': '.$e);
			\DB::rollback_transaction();
			return false;
		}
	}


	/**
	 * Send the binary data of a specific sized variant of an asset to the client, resizing if needed.
	 * @param string $size Choose size variant to render. 'original', 'large', 'thumbnail'
	 * @return void
	 */
	public function render(string $size)
	{
		// register a shutdown function that will render the image
		// allowing all of fuel's other shutdown methods to do their jobs
		\Event::register('fuel-shutdown', function() use($size) {

			// set a few ini settings before we start
			ini_get('zlib.output_compression') and ini_set('zlib.output_compression', 0);
			! ini_get('safe_mode') and set_time_limit(0);

			if ( ! $this->_storage_driver->exists($this->id, $size))
			{
				if ($size === 'original') throw new HttpNotFoundException;
				$asset_path = $this->build_size($size);
			}
			else
			{
				$asset_path = $this->download_asset_to_temp_file($this->id, $size);
			}

			if ( ! file_exists($asset_path)) throw new HttpNotFoundException;
			$bytes = filesize($asset_path);
			// turn off and clean output buffer
			while (ob_get_level() > 0) ob_end_clean();

			// Set headers and send the file
			header("Content-Type: {$this->get_mime_type()}");
			header("Content-Disposition: inline; filename=\"{$this->title}\"");
			header("Content-Length: {$bytes}");
			header('Content-Transfer-Encoding: binary');
			$fp = fopen($asset_path, 'rb');
			fpassthru($fp); // write file directly to output
			unlink($asset_path);
		});

		exit; // don't do anything else, just run shutdown
	}

	/**
	 * @return string Mime type based on $this->type for use in http headers
	 */
	public function get_mime_type(): string
	{
		switch ($this->type)
		{
			case 'png':
			case 'gif':
				return "image/{$this->type}";

			case 'jpeg':
			case 'jpg':
				return 'image/jpeg';

			case 'mp3':
				return 'audio/mpeg';
		}
	}

	/**
	 * Build a specified size of an asset.
	 * @param string $size Choose size variant to render. 'original', 'large', 'thumbnail'
	 * @return array Array containing 'data' (binary image data), and 'bytes' (integer byte size of image)
	 */
	protected function build_size(string $size): string
	{
		$ext = ".{$this->type}";
		$crop = $size === 'thumbnail';
		switch ($size)
		{
			case 'thumbnail':
				$width = 75;
				break;

			case 'large':
				$width = 1024;
				break;

			default: // @codingStandardsIgnoreLine
				throw("Asset size not supported: '{$size}'");
				break;
		}

		$this->_storage_driver->lock_for_processing($this->id, $size);

		// get the original file
		$original_asset_path = $this->download_asset_to_temp_file($this->id, 'original');

		// add extension to tmp file so Image knows how to read it
		rename($original_asset_path, $original_asset_path .= $ext);

		// create temp file to put resized image into
		$resized_file_path = tempnam(sys_get_temp_dir(), "{$this->id}_{$size}_");
		// add extension to tmp file so Image knows what to write it
		rename($resized_file_path, $resized_file_path .= $ext);

		// Resize the image
		try
		{
			if ($crop)
			{
				\Image::load($original_asset_path)
					->crop_resize($width, $width)
					->save($resized_file_path);
			}
			else
			{
				\Image::load($original_asset_path)
					->resize($width, $width)
					->save($resized_file_path);
			}
		}
		catch (\RuntimeException $e)
		{
			\LOG::error($e);
			throw($e);
		}

		$this->_storage_driver->upload($this, $resized_file_path, $size);

		// update asset_data
		$this->_storage_driver->unlock_for_processing($this->id, $size);

		// close the file handles and delete temp files
		unlink($original_asset_path);

		return $resized_file_path;
	}

	public function upload_asset_data(string $source_asset_path)
	{
		$this->_storage_driver->upload($this, $source_asset_path, 'original', false);
	}

	protected function download_asset_to_temp_file(string $id, string $size): string
	{
		// create temp file to copy image into
		// Fuel's image manipulation requires the images to be files
		$tmp_file_path = tempnam(sys_get_temp_dir(), "{$id}_{$size}_");

		$this->_storage_driver->download($id, $size, $tmp_file_path);

		return $tmp_file_path;
	}


	public function db_remove()
	{
		if (strlen($this->id) <= 0) return false;

		\DB::start_transaction();

		try
		{
			// delete asset
			\DB::delete('asset')
				->where('id', $this->id)
				->limit(1)
				->execute();

			// delete data
			\Db::delete('asset_data')
				->where('id', $this->id)
				->execute();

			// @TODO: delete from s3?

			// delete perms
			Perm_Manager::clear_all_perms_for_object($this->id, Perm::ASSET);

			\DB::commit_transaction();

			// clear this object
			$this->__construct();
			return true;
		}
		catch (Exception $e)
		{
			\LOG::error('The following exception occured while attempting to remove asset id, '.$this->id.', for user id,'.\Model_User::find_current_id().': '.$e);
			\DB::rollback_transaction();
			return false;
		}
	}

	/**
	 * getGames is set to true if we want to get a list of games that the
	 *  asset is used in forUID, if getGames is set, will be set if the games
	 *  list should be specific for that user
	 *
	 * NEEDS DOCUMENTATION
	 *
	 * @param object The database manager
	 * @param int NEEDS DOCUMENTATION
	 */
	public function db_get($id)
	{
		// Get asset
		$results = \DB::select()
			->from('asset')
			->where('id', $id)
			->execute();

		if ($results->count() > 0)
		{
			$this->__construct($results[0]);
			return true;
		}
		return false;
	}
}
