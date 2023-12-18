<?php

@include_once __DIR__ . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__)->load(); // TODO: Add configurable path for .env

Kirby::plugin('robinscholz/kirby-mux', [
    'options' => [
        'optimizeDiskSpace' => false
    ],
    'translations' => [
        'en' => [
            'field.blocks.mux-video.thumbnail' => 'Thumbnail',
            'field.blocks.mux-video.thumbnail.help' => 'In seconds',
        ],
        'de' => [
            'field.blocks.mux-video.thumbnail' => 'Thumbnail aus Frame generieren',
            'field.blocks.mux-video.thumbnail.help' => 'In Sekunden',
        ],
    ],
    'blueprints' => [
        'files/mux-video' => __DIR__ . '/blueprints/files/mux-video.yml',
        'blocks/mux-video' => __DIR__ . '/blueprints/blocks/mux-video.yml'
    ],
    'fileMethods' => [
        'muxPlaybackId' => function () {
            return json_decode($this->mux())->playback_ids[0]->id;
        },
        'muxUrlLow' => function () {

            $assetId = json_decode($this->mux())->id;
            $playbackId = json_decode($this->mux())->playback_ids[0]->id;

            if (json_decode($this->mux())->static_renditions->status != 'ready') {
                // Authenticate
                $assetsApi = KirbyMux\Auth::assetsApi();

                $assetData = $assetsApi->getAsset($assetId)->getData();

                while (true) {
                    // ========== get-asset ==========
                    $waitingAsset = $assetsApi->getAsset($assetId);
                    if ($waitingAsset->getData()['static_renditions']['status'] != 'ready') {
                        // print("    still waiting for asset to become ready...\n");
                        sleep(1);
                    } else {
                        $this->update([
                            'mux' => $waitingAsset->getData()
                        ]);
                        break;
                    }
                }
            }

            return "https://stream.mux.com/" . $playbackId . "/low.mp4";
        },
        'muxUrlHigh' => function () {
            $assetId = json_decode($this->mux())->id;
            $playbackId = json_decode($this->mux())->playback_ids[0]->id;

            if (json_decode($this->mux())->static_renditions->status != 'ready') {
                // Authenticate
                $assetsApi = KirbyMux\Auth::assetsApi();

                $assetData = $assetsApi->getAsset($assetId)->getData();

                while (true) {
                    // ========== get-asset ==========
                    $waitingAsset = $assetsApi->getAsset($assetId);
                    if ($waitingAsset->getData()['static_renditions']['status'] != 'ready') {
                        // print("    still waiting for asset to become ready...\n");
                        sleep(1);
                    } else {
                        $this->update([
                            'mux' => $waitingAsset->getData()
                        ]);
                        break;
                    }
                }
            }

            $static_renditions = json_decode($this->mux())->static_renditions;

            return ($static_renditions->status == 'ready' && count($static_renditions->files) > 1) ? "https://stream.mux.com/" . $playbackId . "/high.mp4" : "https://stream.mux.com/" . $playbackId . "/low.mp4";
        },
        'muxUrlStream' => function () {
            $playbackId = json_decode($this->mux())->playback_ids[0]->id;
            return "https://stream.mux.com/" . $playbackId . ".m3u8";
        },
        'muxThumbnail' => function ($width = null, $height = null, Float $time = null, String $extension = 'jpg') {
            $playbackId = json_decode($this->mux())->playback_ids[0]->id;
            $url = "https://image.mux.com/" . $playbackId . "/thumbnail." . $extension;

            $params = [];
            if ($width) {
                $params['width'] = $width;
            }
            if ($height) {
                $params['height'] = $height;
                $params['fit_mode'] = 'smartcrop';
            }
            if ($time !== null) {
                $params['time'] = $time;
            }
            if (count($params)) {
                $url .= '?' . http_build_query($params);
            }
            return $url;
        },
        'muxThumbnailAnimated' => function ($width = null, $height = null, $start = null, $end = null, $fps = null, String $extension = 'gif') {
            $playbackId = json_decode($this->mux())->playback_ids[0]->id;
            $url = "https://image.mux.com/" . $playbackId . "/animated." . $extension;

            $params = [];
            if ($width) {
                $params['width'] = $width;
            }
            if ($height) {
                $params['height'] = $height;
            }
            if ($start) {
                $params['start'] = $start;
            }
            if ($end) {
                $params['end'] = $end;
            }
            if ($fps) {
                $params['fps'] = $fps;
            }
            if (count($params)) {
                $url .= '?' . http_build_query($params);
            }
            return $url;
        },
        'muxKirbyThumbnail' => function () {
            return $this->parent()->file(F::name($this->filename()) . '-thumbnail.jpg');
        },
    ],
    'hooks' => [
        'file.create:after' => function (Kirby\Cms\File $file) {
            if ($file->type() !== 'video') {
                return;
            }

            // Authenticate
            $assetsApi = KirbyMux\Auth::assetsApi();

            // Upload the file to mux
            $result = KirbyMux\Methods::upload($assetsApi, $file->url());

            // Save mux data
            try {

                $file = $file->update([
                    'mux' => $result->getData()
                ]);

                // Wait for the asset to become ready...
                if ($result->getData()->getStatus() != 'ready') {
                    // print("    waiting for asset to become ready...\n");
                    while (true) {
                        // ========== get-asset ==========
                        $waitingAsset = $assetsApi->getAsset($result->getData()->getId());
                        if ($waitingAsset->getData()->getStatus() != 'ready') {
                            // print("    still waiting for asset to become ready...\n");
                            sleep(1);
                        } else {
                            $url = "https://image.mux.com/" . $result->getData()->getPlaybackIds()[0]->getId() . "/thumbnail.jpg";
                            $imagedata = file_get_contents($url);
                            F::write($file->parent()->root() . '/' . $file->name() . '-thumbnail.jpg', $imagedata);
                            $file = $file->update([
                                'mux' => $waitingAsset->getData()
                            ]);
                            if (option('robinscholz.kirby-mux.optimizeDiskSpace', false)) {
                                $videodata = file_get_contents($file->muxUrlLow());
                                F::write($file->parent()->root() . '/' . $file->name() . '.mp4', $videodata);
                            }
                            break;
                        }
                    }
                }
            } catch (Exception $e) {
                throw new Exception($e->getMessage());
            }
        },
        'file.delete:before' => function (Kirby\Cms\File $file) {
            if ($file->type() !== 'video') {
                return;
            }

            // Authentication setup
            $assetsApi = KirbyMux\Auth::assetsApi();

            // Get mux Id
            $muxId = json_decode($file->mux())->id;

            // Delete Asset
            try {
                $assetsApi->deleteAsset($muxId);
                F::remove($file->parent()->root() . '/' . $file->name() . '-thumbnail.jpg');
                F::remove($file->parent()->root() . '/' . $file->name() . '-thumbnail.jpg.txt');
            } catch (Exception $e) {
                throw new Exception($e->getMessage());
            }
        },
        'file.replace:before' => function (Kirby\Cms\File $file, Kirby\Filesystem\File $upload) {
            if ($upload->type() !== 'video') {
                return;
            }

            // Authentication setup
            $assetsApi = KirbyMux\Auth::assetsApi();

            // Get old mux Id
            $muxId = json_decode($file->mux())->id;

            // Delete old asset
            try {
                $assetsApi->deleteAsset($muxId);
            } catch (Exception $e) {
                throw new Exception($e->getMessage());
            }
        },
        'file.replace:after' => function (Kirby\Cms\File $newFile, Kirby\Cms\File $oldFile) {
            if ($newFile->type() !== 'video') {
                return;
            }

            // Authentication setup
            $assetsApi = KirbyMux\Auth::assetsApi();

            // Upload new file to mux
            $result = KirbyMux\Methods::upload($assetsApi, $newFile->url());

            // Save playback Id
            try {
                $newFile = $newFile->update([
                    'mux' => $result->getData()
                ]);

                // Wait for the asset to become ready...
                if ($result->getData()->getStatus() != 'ready') {
                    // print("    waiting for asset to become ready...\n");
                    while (true) {
                        // ========== get-asset ==========
                        $waitingAsset = $assetsApi->getAsset($result->getData()->getId());
                        if ($waitingAsset->getData()->getStatus() != 'ready') {
                            // print("    still waiting for asset to become ready...\n");
                            sleep(1);
                        } else {
                            $url = "https://image.mux.com/" . $result->getData()->getPlaybackIds()[0]->getId() . "/thumbnail.jpg?time=0";
                            $imagedata = file_get_contents($url);
                            F::write($file->parent()->root() . '/' . $file->name() . '-thumbnail.jpg', $imagedata);
                            $newFile = $newFile->update([
                                'mux' => $waitingAsset->getData(),
                            ]);
                            if (option('robinscholz.kirby-mux.optimizeDiskSpace', false)) {
                                $videodata = file_get_contents($newFile->muxUrlLow());
                                F::write($newFile->parent()->root() . '/' . $newFile->name() . '.mp4', $videodata);
                            }
                            break;
                        }
                    }
                }
            } catch (Exception $e) {
                throw new Exception($e->getMessage());
            }
        }
    ]
]);
