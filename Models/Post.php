<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
	// Post -> table_name = posts
	// costume table name :
	// protected $table ='table_name'

	//define cloumn name
	protected $fillable = array('title', 'content', 'status', 'user_id');

	// untuk melakukan update field created_at dan update_at secara otomatis
	public $timestamps = true;
}