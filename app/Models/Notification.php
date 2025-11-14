<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $table = 'notifications';
    protected $primaryKey = 'Id_Notification';
    public $timestamps = false;

    protected $fillable = [
        'Status_Notification'
    ];
}
