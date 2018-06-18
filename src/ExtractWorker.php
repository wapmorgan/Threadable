<?php
namespace wapmorgan\Threadable;

use Exception;
use ZipArchive;

/**
 * Extracts an archive to a specific folder.
 * Payload structure:
 * - archive - full path to archive
 * - output - directory in which archive content should be extracted
 */
class ExtractWorker extends Worker
{

    /**
     * @param array $data
     * @return bool
     * @throws Exception
     */
    public function onPayload($data)
    {
        if (empty($data['archive']) || empty($data['output']))
            throw new Exception('Payload should contain two elements: `archive` - archive file, `output` - place to extract.');

        $archive = new ZipArchive();
        $result = $archive->open($data['archive']);
        if ($result !== true)
            throw new Exception('Could not open archive: '.$archive->getStatusString());

        return $archive->extractTo($data['output']);
    }
}