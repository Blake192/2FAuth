<?php

namespace App\Api\v1\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\Controller;
use App\Services\LogoService;
use Illuminate\Support\Facades\App;


class IconController extends Controller
{
    /**
     * Handle uploaded icon image
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function upload(Request $request)
    {
        $this->validate($request, [
            'icon' => 'required|image',
        ]);

        $icon = $request->file('icon');
        $path = $icon instanceof \Illuminate\Http\UploadedFile ? $icon->store('', 'icons') : false;

        return $path
                ? response()->json(['filename' => pathinfo($path)['basename']], 201)
                : response()->json(['message' => __('errors.file_upload_failed')], 500);
    }


    /**
     * Fetch a logo
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function fetch(Request $request)
    {
        $this->validate($request, [
            'service' => 'string|regex:/^[^:]+$/i',
        ]);
        
        $logoService = App::make(LogoService::class);
        $icon = $logoService->getIcon($request->service);

        return $icon
            ? response()->json(['filename' => $icon], 201)
            : response()->json(null, 204);
    }
    

    /**
     * delete an icon
     *
     * @param  string $icon
     * @return \Illuminate\Http\JsonResponse
     */
    public function delete(string $icon)
    {
        Storage::disk('icons')->delete($icon); 

        return response()->json(null, 204);
    }
}