<?php
namespace wapmorgan\Threadable;

use Exception;

/**
 * Downloads file from remote server.
 * Payload structure:
 * - source - remote file url
 * - target - local file path
 */
class DownloadWorker extends Worker
{
    public function onPayload($data)
    {
        if (empty($data['source']) || empty($data['target']))
            throw new Exception();
        return copy($data['source'], $data['target']);
    }
}
