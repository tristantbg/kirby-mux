<?php

namespace KirbyMux;

use Kirby\Cms\File;
use MuxPhp;

class Methods
{
    public static function upload($assetsApi, $url)
    {
        $file = $_ENV['MUX_DEV'] === 'true' ? "https://storage.googleapis.com/muxdemofiles/mux-video-intro.mp4" : $url;
        $input = new MuxPhp\Models\InputSettings(["url" => $file]);
        $createAssetRequest = new MuxPhp\Models\CreateAssetRequest([
            "input" => $input,
            "playback_policy" => [MuxPhp\Models\PlaybackPolicy::_PUBLIC],
            'mp4_support' => 'capped-1080p',
            'static_renditions' => [
                new MuxPhp\Models\CreateStaticRenditionRequest(['resolution' => '270p']),
                new MuxPhp\Models\CreateStaticRenditionRequest(['resolution' => '720p']),
                new MuxPhp\Models\CreateStaticRenditionRequest(['resolution' => '1080p']),
            ]
        ]);

        return $assetsApi->createAsset($createAssetRequest);
    }

    /**
     * Poll Mux until the asset status is 'ready', then return the asset data.
     */
    public static function waitForAssetReady($assetsApi, string $assetId)
    {
        while (true) {
            $asset = $assetsApi->getAsset($assetId);
            if ($asset->getData()->getStatus() === 'ready') {
                return $asset;
            }
            sleep(1);
        }
    }

    /**
     * Poll Mux until static renditions are ready, update the file, and return refreshed mux data.
     */
    public static function ensureRenditionsReady(File $file): object
    {
        $muxData = json_decode($file->mux());

        if ($muxData->status === 'preparing' || !isset($muxData->static_renditions) || $muxData->static_renditions->status !== 'ready') {
            $assetsApi = Auth::assetsApi();

            while (true) {
                $asset = $assetsApi->getAsset($muxData->id);
                if ($asset->getData()['static_renditions']['status'] === 'ready') {
                    $file->update(['mux' => $asset->getData()]);
                    $muxData = json_decode($file->mux());
                    break;
                }
                sleep(1);
            }
        }

        return $muxData;
    }

    /**
     * Save a Mux thumbnail to disk and return the Kirby file object.
     */
    public static function saveThumbnail(File $file, string $playbackId, ?float $time = null): void
    {
        $url = "https://image.mux.com/" . $playbackId . "/thumbnail.jpg";
        if ($time !== null) {
            $url .= '?time=' . $time;
        }
        $imagedata = file_get_contents($url);
        \F::write($file->parent()->root() . '/' . $file->name() . '-thumbnail.jpg', $imagedata);
    }

    /**
     * Handle post-upload flow: wait for ready, save thumbnail, optionally optimize disk space.
     */
    public static function processAfterUpload($assetsApi, File $file, $result): File
    {
        $file = $file->update(['mux' => $result->getData()]);

        if ($result->getData()->getStatus() !== 'ready') {
            $asset = static::waitForAssetReady($assetsApi, $result->getData()->getId());
            $playbackId = $result->getData()->getPlaybackIds()[0]->getId();
            static::saveThumbnail($file, $playbackId);
            $file = $file->update(['mux' => $asset->getData()]);

            if (option('tristantbg.kirby-mux.optimizeDiskSpace', false)) {
                $videodata = file_get_contents($file->muxUrlLow());
                \F::write($file->parent()->root() . '/' . $file->name() . '.mp4', $videodata);
            }
        }

        return $file;
    }
}
