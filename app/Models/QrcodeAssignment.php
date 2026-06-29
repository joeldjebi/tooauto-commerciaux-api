<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QrcodeAssignment extends Model
{
    protected $fillable = [
        'commercial_id', 'qrcode_id', 'assigned_at', 'user_id'
    ];

    protected $dates = ['assigned_at'];

    public function commercial()
    {
        return $this->belongsTo(Commercial::class);
    }
	
	
    public function qrcode()
    {
        return $this->belongsTo(QrcodeGenerate::class);
    }
	
	
	public function user()
	{
		return $this->belongsTo(User::class);
	}

}