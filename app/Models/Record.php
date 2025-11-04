<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Record extends Model
{
    protected $table = 'records';
    protected $primaryKey = 'Id_Record';
    public $timestamps = false;

    protected $fillable = [
        'Sequence_No_Record',
        'Scan_Time_Record',
        'Detect_Time_Record'
    ];

    public function comparison()
    {
        return $this->belongsTo(Comparison::class, 'Id_Comparison', 'Id_Comparison');
    }
}
