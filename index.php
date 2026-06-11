<?php

@include_once __DIR__ . '/vendor/autoload.php';

Kirby::plugin('tristantbg/kirby-mux', [
    'options' => [
        'tokenId' => '',
        'tokenSecret' => '',
        'webhookSecret' => '',
        'environmentId' => '',
        'dev' => false,
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
    'filePreviews' => [
        KirbyMux\MuxFilePreview::class
    ],
    'fileMethods' => [
        'muxPlaybackId' => function () {
            $data = json_decode($this->mux());
            return $data->playback_ids[0]->id ?? null;
        },
        'muxUrlLow' => function () {
            $playbackId = $this->muxPlaybackId();
            if (!$playbackId) throw new Exception('No Mux playback ID found for file: ' . $this->filename());
            return "https://stream.mux.com/{$playbackId}/270p.mp4";
        },
        'muxUrlHigh' => function () {
            $playbackId = $this->muxPlaybackId();
            if (!$playbackId) throw new Exception('No Mux playback ID found for file: ' . $this->filename());
            $data = json_decode($this->mux(), true);
            $renditions = $data["static_renditions"] ?? null;
            $resolution = ($renditions && $renditions["status"] === 'ready' && count($renditions["files"]) > 1) ? '1080p' : '720p';
            return "https://stream.mux.com/{$playbackId}/{$resolution}.mp4";
        },
        'muxUrlStream' => function () {
            $playbackId = $this->muxPlaybackId();
            if (!$playbackId) throw new Exception('No Mux playback ID found for file: ' . $this->filename());
            return "https://stream.mux.com/{$playbackId}.m3u8";
        },
        'muxThumbnail' => function ($width = null, $height = null, $time = null, String $extension = 'jpg') {
            $playbackId = $this->muxPlaybackId();
            if (!$playbackId) throw new Exception('No Mux playback ID found for file: ' . $this->filename());
            $url = "https://image.mux.com/{$playbackId}/thumbnail.{$extension}";

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
            $playbackId = $this->muxPlaybackId();
            if (!$playbackId) throw new Exception('No Mux playback ID found for file: ' . $this->filename());
            $url = "https://image.mux.com/{$playbackId}/animated.{$extension}";

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
            $muxThumbnail = $this->parent()->file(F::name($this->filename()) . '-thumbnail.jpg');

            if (!$muxThumbnail) {
                $playbackId = $this->muxPlaybackId();
                if (!$playbackId) throw new Exception('No Mux playback ID found for file: ' . $this->filename());
                $url = "https://image.mux.com/{$playbackId}/thumbnail.jpg";
                $imagedata = file_get_contents($url);
                F::write($this->parent()->root() . '/' . $this->name() . '-thumbnail.jpg', $imagedata);
                $muxThumbnail = $this->parent()->file(F::name($this->filename()) . '-thumbnail.jpg');
            }

            return $muxThumbnail;
        },
    ],
    'hooks' => [
        'file.create:after' => function (Kirby\Cms\File $file) {
            if ($file->type() !== 'video') {
                return;
            }

            $assetsApi = KirbyMux\Auth::assetsApi();
            $result = KirbyMux\Methods::upload($assetsApi, $file->url(), $file);

            try {
                KirbyMux\Methods::processAfterUpload($assetsApi, $file, $result);
            } catch (Exception $e) {
                throw new Exception($e->getMessage());
            }
        },
        'file.delete:before' => function (Kirby\Cms\File $file) {
            if ($file->type() !== 'video') {
                return;
            }

            $assetsApi = KirbyMux\Auth::assetsApi();
            $muxId = json_decode($file->mux())->id;

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

            $assetsApi = KirbyMux\Auth::assetsApi();
            $muxId = json_decode($file->mux())->id;

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

            $assetsApi = KirbyMux\Auth::assetsApi();
            $result = KirbyMux\Methods::upload($assetsApi, $newFile->url(), $newFile);

            try {
                KirbyMux\Methods::processAfterUpload($assetsApi, $newFile, $result);
            } catch (Exception $e) {
                throw new Exception($e->getMessage());
            }
        }
    ],
    'routes' => [
        [
            'pattern' => 'mux/webhook',
            'method' => 'POST',
            'action' => function () {
                return KirbyMux\Methods::handleWebhook();
            }
        ]
    ],
    'areas' => [
        'mux' => function () {
            return [
                'label' => 'Mux',
                'icon' => 'video',
                'menu' => true,
                'link' => 'mux',
                'views' => [
                    [
                        'pattern' => 'mux',
                        'action' => function () {
                            return [
                                'component' => 'k-mux-view',
                                'title' => 'Mux',
                                'props' => KirbyMux\Methods::panelVideos()
                            ];
                        }
                    ]
                ]
            ];
        }
    ],
    'api' => [
        'routes' => [
            [
                'pattern' => 'mux/videos',
                'method' => 'GET',
                'action' => function () {
                    return KirbyMux\Methods::panelVideos();
                }
            ],
            [
                'pattern' => 'mux/refetch',
                'method' => 'POST',
                'action' => function () {
                    return KirbyMux\Methods::refetch(get('id'));
                }
            ]
        ]
    ]
]);
