<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * A scheduled task to gather data usee in plugin dashboards and reports.
 *
 * @package    local_smartmedia
 * @copyright  2019 Matt Porritt <mattp@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_smartmedia\task;

use core\task\scheduled_task;
use \local_smartmedia\pricing\aws_ets_pricing_client;
use \local_smartmedia\pricing\aws_rekog_pricing_client;
use local_smartmedia\pricing\aws_transcribe_pricing_client;
use stdClass;

defined('MOODLE_INTERNAL') || die();

/**
 * A scheduled task to gather data usee in plugin dashboards and reports.
 *
 * @copyright  2019 Matt Porritt <mattp@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_process extends scheduled_task {

    /**
     * Audio mime types.
     */
    private const AUDIO_MIME_TYPES = array(
        'audio/aac',
        'audio/au',
        'audio/mp3',
        'audio/mp4',
        'audio/ogg',
        'audio/wav',
        'audio/x-aiff',
        'audio/x-mpegurl',
        'audio/x-ms-wma',
        'audio/x-pn-realaudio-plugin',
        'audio/x-matroska',
    );

    /**
     * Video mime types.
     */
    private const VIDEO_MIME_TYPES = array(
        'video/mp4',
        'video/mpeg',
        'video/ogg',
        'video/quicktime',
        'video/webm',
        'video/x-dv',
        'video/x-flv',
        'video/x-ms-asf',
        'video/x-ms-wm',
        'video/x-ms-wmv',
        'video/x-matroska',
        'video/x-matroska-3d',
        'video/MP2T'.
        'video/x-sgi-movie',
    );

    /** @var array - Cache holder for the pricing clients. */
    private $pricing;

    public function __construct() {
        $this->pricing = [];
    }

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('task:reportprocess', 'local_smartmedia');
    }

    /**
     * Count all the files in the Moodle files table,
     * except those added by smart media.
     *
     * @return int $result Count of files.
     */
    private function get_all_file_count() : int {
        global $DB;

        $select = 'filearea <> :filearea AND filename <> :filename AND component <> :component';
        $params = array(
            'filearea' => 'draft', // Don't get draft files.
            'filename' => '.', // Don't get directories.
            'component' => 'local_smartmedia'  // Don't count files added by smartmedia itself.
        );

        $result = $DB->count_records_select('files', $select, $params);

        return $result;

    }

    /**
     * Count all the audio files in the Moodle files table,
     * except those added by smart media.
     *
     * @return int $result Count of files.
     */
    private function get_audio_file_count() : int {
        global $DB;

        list($insql, $inparams) = $DB->get_in_or_equal(self::AUDIO_MIME_TYPES);
        $select = "mimetype $insql AND component <> ? AND filearea <> ? AND filename <> ?";
        $inparams[] = 'local_smartmedia'; // Don't count files added by smartmedia itself.
        $inparams[] = 'draft'; // Don't get draft files.
        $inparams[] = '.';

        $result = $DB->count_records_select('files', $select, $inparams);

        return $result;
    }

    /**
     * Count all the video files in the Moodle files table,
     * except those added by smart media.
     *
     * @return int $result Count of files.
     */
    private function get_video_file_count() : int {
        global $DB;

        list($insql, $inparams) = $DB->get_in_or_equal(self::VIDEO_MIME_TYPES);
        $select = "mimetype $insql AND component <> ? AND filearea <> ? AND filename <> ?";
        $inparams[] = 'local_smartmedia';  // Don't count files added by smartmedia itself.
        $inparams[] = 'draft'; // Don't get draft files.
        $inparams[] = '.';
        $result = $DB->count_records_select('files', $select, $inparams);

        return $result;
    }

    /**
     * Count all the unique file objects (contenthashes) for
     * multimedia files from the Moodle files table.
     *
     * @return int count of found records.
     */
    private function get_unique_multimedia_objects() : int {
        global $DB;

        $mimetypes = array_merge(self::AUDIO_MIME_TYPES, self::VIDEO_MIME_TYPES);
        list($insql, $inparams) = $DB->get_in_or_equal($mimetypes);
        $inparams[] = 'local_smartmedia';
        $inparams[] = 'draft'; // Don't get draft files.
        $inparams[] = '.';
        $sql = "SELECT COUNT(DISTINCT contenthash) AS count
                  FROM {files}
                 WHERE mimetype $insql
                       AND component <> ?
                       AND filearea <> ?
                       AND filename <> ?";
        $result = $DB->count_records_sql($sql, $inparams);

        return $result;
    }

    /**
     * Count all the multimedia file objects
     * that have had metadata extracted.
     *
     * @return int count of found records.
     */
    private function get_metadata_processed_files() : int {
        global $DB;

        $result = $DB->count_records('local_smartmedia_data');

        return $result;
    }

    /**
     * Count all the multimedia file objects
     * that have been transcoded.
     *
     * @return int count of found records.
     */
    private function get_transcoded_files() : int {
        global $DB;

        $conditions = array('status' => 'Finished');
        $result = $DB->count_records('local_smartmedia_report_over', $conditions);

        return $result;
    }

    /**
     * Add a key value pair to the report database table.
     *
     * @param string $name Name of the value to store.
     * @param mixed $value Value to store.
     */
    private function update_report_data(string $name, $value) : void {
        global $DB;

        $datarecord = new \stdClass();
        $datarecord->name = $name;
        $datarecord->value = $value;

        try {
            $transaction = $DB->start_delegated_transaction();
            $namerecord = $DB->get_record('local_smartmedia_reports', array('name' => $name), 'id');

            if ($namerecord) {
                $datarecord->id = $namerecord->id;
                $DB->update_record('local_smartmedia_reports', $datarecord);
            } else {
                $DB->insert_record('local_smartmedia_reports', $datarecord);
            }

            $transaction->allow_commit();

        } catch (\Exception $e) {
            $transaction->rollback($e);
        }
    }

    /**
     * Get the file type.
     *
     * @param \stdClass $record  Record from metadata table for file.
     * @throws \coding_exception
     * @return string $format File format.
     */
    private function get_file_type(\stdClass $record) : string {
        if (empty($record->videostreams)) {
            if (!empty($record->audiostreams)) {
                $format = get_string('report:typeaudio', 'local_smartmedia');
            } else {
                // We should never get here due to the WHERE clause excluding rows with no video or audio data.
                throw new \coding_exception(
                    'No audio or video stream in {local_smartmedia_data} contenthash' . $record->contenthash);
            }
        } else {
            $format = get_string('report:typevideo', 'local_smartmedia');
        }

        return $format;
    }

    /**
     * Get the presets used for a given conversion.
     *
     * @param int $convid The conversion id to get the presets for.
     * @return array $presetids The preset ids for the conversion.
     */
    private function get_conversion_presets(int $convid) : array {
        global $DB;

        $select = 'convid = ?';
        $params = array($convid);
        $presetids = $DB->get_fieldset_select('local_smartmedia_presets', 'preset', $select, $params);

        return $presetids;
    }

    /**
     * Get the enrichment settings used for a given conversion.
     *
     * @param int $convid The conversion id to get the presets for.
     * @return array $presetids The preset ids for the conversion.
     */
    private function get_enrichment_settings(int $convid) : stdClass {
        global $DB;
        $record = $DB->get_record('local_smartmedia_conv', ['id' => $convid]);
        // Remove non-enrichment settings.
        unset($record->id);
        unset($record->pathnamehash);
        unset($record->contenthash);
        unset($record->status);
        unset($record->transcoder_status);

        // Map to bool for completed process.
        // This transforms the status code (200, 404) to a boolean status for this process.
        // 200 is true, the process completed, the rest is false.
        $record = array_map(function($el) {
            return (int) $el === \local_smartmedia\conversion::CONVERSION_FINISHED;
        }, (array) $record);

        return (object) $record;
    }

    /**
     * Get the cost to transcode a file with currently configured settings.
     *
     * @param aws_ets_pricing_client $pricingclient
     * @param \local_smartmedia\aws_elastic_transcoder $transcoder
     * @param \stdClass $record Record from metadata table for file.
     * @return float $cost The calculated transcoding cost.
     */
    private function get_file_cost(
            aws_ets_pricing_client $transcodepricingclient,
            aws_rekog_pricing_client $rekogpricingclient,
            aws_transcribe_pricing_client $transcribepricingclient,
            \local_smartmedia\aws_elastic_transcoder $transcoder,  \stdClass $record) : float {

        // Check if we have already cached the pricing.
        if (empty($this->pricing)) {
            // Get the location pricing for the AWS region set.
            $location = get_config('local_smartmedia', 'api_region');

            $this->pricing = [
                'transcode' => $transcodepricingclient->get_location_pricing($location),
                'rekog' => $rekogpricingclient->get_location_pricing($location),
                'transcribe' => $transcribepricingclient->get_location_pricing($location)
            ];
        }

        $transcodelocationpricing = $this->pricing['transcode'];
        $rekoglocationpricing = $this->pricing['rekog'];
        $transcribelocationpricing = $this->pricing['transcribe'];

        // Get the preset ids for this conversion.
        $presetids = $this->get_conversion_presets($record->id);

        // Get the Elastic Transcoder presets which have been set.
        $presets = $transcoder->get_presets($presetids);
        $enrichmentsettings = $this->get_enrichment_settings($record->id);
        $rekogsettings = [
            'face_detection' => $enrichmentsettings->rekog_face_status ?? false,
            'content_moderation' => $enrichmentsettings->rekog_moderation_status ?? false,
            'label_detection' => $enrichmentsettings->rekog_label_status ?? false,
            'person_tracking' => $enrichmentsettings->rekog_person_status ?? false,
        ];
        $transcribe = $enrichmentsettings->transcribe_status ?? false;

        $pricingcalculator = new \local_smartmedia\pricing_calculator(
            $transcodelocationpricing,
            $rekoglocationpricing,
            $transcribelocationpricing,
            $presets, $rekogsettings,
            $transcribe
        );

        $cost = $pricingcalculator->calculate_transcode_cost(
            $record->height, $record->duration, $record->videostreams, $record->audiostreams);
        $cost += $pricingcalculator->calculate_rekog_cost($record->duration);
        $cost += $pricingcalculator->calculate_transcribe_cost($record->duration);

        return $cost;
    }

    /**
     * Convert the smartmedia conversion processing code
     * to a human readable value.
     *
     * @param int $code The status code.
     * @return string The human readable value.
     */
    private function get_file_status(int $code) : string {
        if ($code == 200) {
            $status = 'Finished';
        } else if ($code == 201) {
            $status = 'In Progress';
        } else if ($code == 202) {
            $status = 'In Progress';
        } else if ($code == 3) {
            $status = 'File Missing';
        } else {
            $status = 'Error';
        }

        return $status;
    }

    /**
     * Get count of files that have the same contenthash
     * from the files table.
     *
     * @param string $contenthash THe contenthash to match.
     * @return int $count The count of file instances.
     */
    private function get_file_count(string $contenthash) : int {
        global $DB;

        $select = 'filearea <> :filearea AND filename <> :filename AND contenthash = :contenthash';
        $params = array(
            'filearea' => 'draft',
            'filename' => '.',
            'contenthash' => $contenthash
        );

        $count = $DB->count_records_select('files', $select, $params);

        return $count;
    }

    /**
     * Populate the report overview table.
     *
     * @param aws_ets_pricing_client $pricingclient
     * @param \local_smartmedia\aws_elastic_transcoder $transcoder
     */
    private function process_overview_report(aws_ets_pricing_client $transcodepricingclient,
        aws_rekog_pricing_client $rekogpricingclient,
        aws_transcribe_pricing_client $transcribepricingclient,
        \local_smartmedia\aws_elastic_transcoder $transcoder) : void {
        global $DB;
        $reportrecords = array();

        // Get metadata and conversion data from DB.
        $sql = 'SELECT d.*, c.status, c.timecreated, c.timecompleted, c.id
                  FROM {local_smartmedia_data} d
                  JOIN {local_smartmedia_conv} c ON c.contenthash = d.contenthash
                 WHERE d.videostreams > 0
                    OR d.audiostreams > 0';

        $rs = $DB->get_recordset_sql($sql);
        foreach ($rs as $record) { // Itterate through records.
            $metadata = json_decode($record->metadata);

            // Manipulate values to store.
            $reportrecord = new \stdClass();
            $reportrecord->contenthash = $record->contenthash;
            $reportrecord->type = $this->get_file_type($record);
            $reportrecord->format = $metadata->formatname;
            $reportrecord->resolution = $record->width . ' X ' . $record->height;
            $reportrecord->duration = round($record->duration, 3);
            $reportrecord->filesize = $record->size;
            $reportrecord->cost = round($this->get_file_cost(
                $transcodepricingclient,
                $rekogpricingclient,
                $transcribepricingclient,
                $transcoder,
                $record), 3);
            $reportrecord->status = $this->get_file_status($record->status);
            $reportrecord->files = $this->get_file_count($record->contenthash);
            $reportrecord->timecreated = $record->timecreated;
            $reportrecord->timecompleted = $record->timecompleted;

            $reportrecords[] = $reportrecord;
        }
        $rs->close();

        // Store values in array before manipulating report table in DB in a transaction.
        try {
            $transaction = $DB->start_delegated_transaction();

            $DB->delete_records('local_smartmedia_report_over');
            $DB->insert_records('local_smartmedia_report_over', $reportrecords);

            $transaction->allow_commit();

        } catch (\Exception $e) {
            $transaction->rollback($e);
        }
    }

    /**
     * Get the total cost for all converted media.
     *
     * @return float
     */
    private function get_total_converted_cost() {
        global $DB;

        $sql = 'select sum(cost) from {local_smartmedia_report_over}';
        $cost = $DB->get_field_sql($sql);

        // Return 0 when no cost exists yet.
        return $cost == null ? 0 : $cost;
    }

    /**
     * Calculate the total cost of transcoding all not converted media items.
     *
     * @param aws_ets_pricing_client $pricingclient
     * @param \local_smartmedia\aws_elastic_transcoder $transcoder
     * @return float|int|null $total cost for all transcoding across all presets, null if total cannot be calculated.
     *
     * @throws \dml_exception
     */
    private function calculate_total_conversion_cost(
        aws_ets_pricing_client $transcodepricingclient,
        aws_rekog_pricing_client $rekogpricingclient,
        aws_transcribe_pricing_client $transcribepricingclient,
        \local_smartmedia\aws_elastic_transcoder $transcoder) : float {

        global $DB;

        // If background processing disabled, return early.
        $background = (bool)get_config('local_smartmedia', 'proactiveconversion');
        if (!$background) {
            return 0;
        }

        $convertfrom = time() - (int)get_config('local_smartmedia', 'convertfrom');

        // Check if we have already cached the pricing.
        if (empty($this->pricing)) {
            // Get the location pricing for the AWS region set.
            $location = get_config('local_smartmedia', 'api_region');

            $this->pricing = [
                'transcode' => $transcodepricingclient->get_location_pricing($location),
                'rekog' => $rekogpricingclient->get_location_pricing($location),
                'transcribe' => $transcribepricingclient->get_location_pricing($location)
            ];
        }

        $transcodelocationpricing = $this->pricing['transcode'];
        $rekoglocationpricing = $this->pricing['rekog'];
        $transcribelocationpricing = $this->pricing['transcribe'];

        // Get the Elastic Transcoder presets which have been set.
        $presets = $transcoder->get_presets();
        // Get enrichment settings for rekog.
        $config = get_config('local_smartmedia');
        $rekogsettings = [
            'face_detection' => $config->detectfaces,
            'content_moderation' => $config->detectmoderation,
            'label_detection' => $config->detectlabels,
            'person_tracking' => $config->detectpeople,
        ];
        $transcribe = $config->transcribe;

        // Get the pricing calculator.
        $pricingcalculator = new \local_smartmedia\pricing_calculator(
            $transcodelocationpricing,
            $rekoglocationpricing,
            $transcribelocationpricing,
            $presets,
            $rekogsettings,
            $transcribe
        );

        if (!$pricingcalculator->has_presets()) {
            $total = null;
        } else {
            // Get the duration of media type content (in seconds), zero if there is no media of type.
            $highdefinitionsql = 'SELECT COALESCE(SUM(d.duration), 0) as duration
                                    FROM {local_smartmedia_data} d
                         LEFT OUTER JOIN {local_smartmedia_conv} c ON d.contenthash = c.contenthash
                               LEFT JOIN (SELECT * FROM {files} ORDER BY timecreated DESC) f ON d.contenthash = f.contenthash
                                     AND f.filearea <> ?
                                   WHERE d.height >= ?
                                     AND d.videostreams > 0
                                     AND c.contenthash IS NULL
                                     AND f.timecreated > ?';
            $highdefinition = $DB->get_record_sql($highdefinitionsql,
                    ['draft', LOCAL_SMARTMEDIA_MINIMUM_HD_HEIGHT, $convertfrom]);

            $standarddefinitionsql = 'SELECT COALESCE(SUM(d.duration), 0) as duration
                                        FROM {local_smartmedia_data} d
                             LEFT OUTER JOIN {local_smartmedia_conv} c ON d.contenthash = c.contenthash
                                   LEFT JOIN (SELECT * FROM {files} ORDER BY timecreated DESC) f ON d.contenthash = f.contenthash
                                         AND f.filearea <> ?
                                       WHERE (d.height < ?)
                                             AND (height > 0)
                                             AND d.videostreams > 0
                                             AND c.contenthash IS NULL
                                             AND f.timecreated > ?';
            $standarddefinition = $DB->get_record_sql($standarddefinitionsql,
                    ['draft', LOCAL_SMARTMEDIA_MINIMUM_HD_HEIGHT, $convertfrom, $convertfrom]);

            $audiosql = 'SELECT COALESCE(SUM(d.duration), 0) as duration
                           FROM {local_smartmedia_data} d
                LEFT OUTER JOIN {local_smartmedia_conv} c ON d.contenthash = c.contenthash
                      LEFT JOIN (SELECT * FROM {files} ORDER BY timecreated DESC) f ON d.contenthash = f.contenthash
                            AND f.filearea <> ?
                          WHERE ((d.height = 0) OR (d.height IS NULL))
                                AND d.audiostreams > 0
                                AND c.contenthash IS NULL
                                AND f.timecreated > ?';
            $audio = $DB->get_record_sql($audiosql, ['draft', $convertfrom, $convertfrom]);

            $totalhdcost = $pricingcalculator->calculate_transcode_cost(LOCAL_SMARTMEDIA_MINIMUM_HD_HEIGHT,
                $highdefinition->duration);
            $totalsdcost = $pricingcalculator->calculate_transcode_cost(LOCAL_SMARTMEDIA_MINIMUM_SD_HEIGHT,
                $standarddefinition->duration);
            $totalaudiocost = $pricingcalculator->calculate_transcode_cost(LOCAL_SMARTMEDIA_AUDIO_HEIGHT,
                $audio->duration);

            // Now add on the rekognition analysis on the transcoded video size only. Audio is not passed to rekog.
            $totalsdcost += $pricingcalculator->calculate_rekog_cost($standarddefinition->duration);
            $totalhdcost += $pricingcalculator->calculate_rekog_cost($highdefinition->duration);

            // Now check Audio against the transcribe pricing.
            $totalaudiocost += $pricingcalculator->calculate_transcribe_cost($audio->duration);
            $totalsdcost += $pricingcalculator->calculate_transcribe_cost($standarddefinition->duration);
            $totalhdcost += $pricingcalculator->calculate_transcribe_cost($highdefinition->duration);

            $total = $totalhdcost + $totalsdcost + $totalaudiocost;
        }

        return $total;
    }

    /**
     * Do the job.
     * Throw exceptions on errors (the job will be retried).
     */
    public function execute() {

        mtrace('local_smartmedia: Processing data for overview report');

        // First we should check whether there are an API keys set.
        $key = get_config('local_smartmedia', 'api_key');
        if (empty($key)) {
            mtrace('local_smartmedia: AWS API key is not set. Exiting early.');
            return;
        }

        // Build the dependencies.
        $api = new \local_smartmedia\aws_api();
        $transcodepricingclient = new aws_ets_pricing_client($api->create_pricing_client());
        $rekogpricingclient = new aws_rekog_pricing_client($api->create_pricing_client());
        $transcribepricingclient = new aws_transcribe_pricing_client($api->create_pricing_client());
        $transcoder = new \local_smartmedia\aws_elastic_transcoder($api->create_elastic_transcoder_client());
        $this->process_overview_report($transcodepricingclient, $rekogpricingclient, $transcribepricingclient, $transcoder);

        mtrace('local_smartmedia: Processing media file data');
        $totalfiles = $this->get_all_file_count(); // Get count of all files in files table.
        $this->update_report_data('totalfiles', $totalfiles);

        $audiofiles = $this->get_audio_file_count(); // Get count of audio files in files table.
        $this->update_report_data('audiofiles', $audiofiles);

        $videofiles = $this->get_video_file_count(); // Get count of video files in files table.
        $this->update_report_data('videofiles', $videofiles);

        mtrace('local_smartmedia: Identifying unique media files');
        $uniquemultimediaobjects = $this->get_unique_multimedia_objects(); // Get count of multimedia objects files table.
        $this->update_report_data('uniquemultimediaobjects', $uniquemultimediaobjects);

        mtrace('local_smartmedia: Discovering media metadata');
        $metadataprocessedfiles = $this->get_metadata_processed_files(); // Get count of processed multimedia files.
        $this->update_report_data('metadataprocessedfiles', $metadataprocessedfiles);

        mtrace('local_smartmedia: Discovering transcoded files');
        $transcodedfiles = $this->get_transcoded_files(); // Get count of transcoded multimedia files.
        $this->update_report_data('transcodedfiles', $transcodedfiles);

        mtrace('local_smartmedia: Calculating total cost of converted media.');
        $convertedcost = $this->get_total_converted_cost();
        $this->update_report_data('convertedcost', $convertedcost);

        mtrace('local_smartmedia: Calculating cost to convert media.');
        $totalcost = $this->calculate_total_conversion_cost($transcodepricingclient,
            $rekogpricingclient,
            $transcribepricingclient,
            $transcoder
        );

        mtrace('local_smartmedia: Writing report data');
        $this->update_report_data('totalcost', $totalcost);
    }

}
