<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

/**
 * Class AdminController
 *
 * @package App\Http\Controllers\admin
 */
class AdminController extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;
}

class bad_class_name {
    private $radius; // should be _radius

    public function doSomething() {
        // short method does nothing
    }

    // long method with many lines
    public function longFunction() {
        $sum = 0;
        for ($i = 0; $i < 60; $i++) {
            $sum += $i;
        }
        return $sum;
    }
}
