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
    /**
     * @param array $data
     * @return bool
     * @throws Exception
     */
    public function onPayload($data)
    {
        if (empty($data['source']) || empty($data['target']))
            throw new Exception('Payload should contain two elements: `source` - file URL, `target` - place to save.');

        $size = self::getRemoteFileSize($data['source']);

        if (copy($data['source'], $data['target']) === false)
            return false;

        return filesize($data['target']) === $size;
    }

    /**
     * @param $path
     * @return int
     * @throws Exception
     */
    static public function getRemoteFileSize($path)
    {
        $fp = fopen($path, 'r');
        $inf = stream_get_meta_data($fp);
        fclose($fp);

        foreach($inf['wrapper_data'] as $v) {
            if (stristr($v,'content-length')) {
                $v = explode(":",$v);
                return (int)trim($v[1]);
            }
        }

        throw new Exception('Can not determine size of remote file '.$path);
    }
}
