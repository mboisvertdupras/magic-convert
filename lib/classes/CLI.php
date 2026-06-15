<?php

namespace MagicConvert;

class CLI extends \WP_CLI_Command
{

    const PARALLEL_MIN_FILES = 50;

    const PROCS_HARD_CAP = 16;

    const SUMMARY_PREFIX = '#MC-SUMMARY ';

    private static function printableSize($bytes) {
        return ($bytes < 10000) ? $bytes . " bytes" : round($bytes / 1024) . ' kb';
    }

    private static function formatLabel($id) {
        if ($id === 'webp') { return 'WebP'; }
        if ($id === 'avif') { return 'AVIF'; }
        return strtoupper((string) $id);
    }

    private static function formatsLabel(array $ids) {
        $labels = array_map(['\MagicConvert\CLI', 'formatLabel'], $ids);
        return implode(' + ', $labels);
    }

    /**
     * Convert images to webp
     *
     * Parallel by default: with no flags at all, `wp magic-convert convert`
     * automatically uses several of the machine's CPU cores and skips images
     * that are already up to date, so a run is safe to re-run and will resume
     * where it left off. Ordinary users never need to configure anything.
     *
     * ## OPTIONS
     * [<location>]
     * : Limit which folders to process to a single location. Ie "uploads/2021". The first part is the
     *   "image root", which must be "uploads", "themes", "plugins", "wp-content" or "index"
     *
     * [--reconvert]
     * : Even convert images that are already converted (new conversions replaces the old conversions)
     *
     * [--only-png]
     * : Only convert PNG images
     *
     * [--only-jpeg]
     * : Only convert jpeg images
     *
     * [--quality]
     * : Override quality with specified (0-100)
     *
     * [--near-lossless]
     * : Override near-lossless quality with specified (0-100)
     *
     * [--alpha-quality]
     * : Override alpha-quality quality with specified (0-100)
     *
     * [--encoding]
     * : Override encoding quality with specified ("auto", "lossy" or "lossless")
     *
     * [--converter=<converter>]
     * : Specify the converter to use (default is to use the stack). Valid options: cwebp | vips | ewww | imagemagick | imagick | gmagick | graphicsmagick | ffmpeg | gd | wpc | ewww
     *
     * [--format=<format>]
     * : Limit conversion to a single output format. Valid options: webp | avif. By default
     *   every enabled format is converted (a file is encoded to each enabled format that still
     *   needs it). The format must also be enabled in the settings.
     *
     * [--procs=<n>]
     * : (Power users) Override the number of parallel processes. `--procs=1`
     *   forces a plain sequential run; higher values fan out that many child
     *   processes. Capped at 16. Mutually exclusive with --shard.
     *
     * [--shard=<spec>]
     * : INTERNAL ORCHESTRATION ONLY — not part of the supported interface and
     *   intentionally undocumented for end users (it is omitted from the README).
     *   It MUST be declared here regardless, because WP-CLI rejects any
     *   undeclared associative argument outright ("unknown --shard parameter"),
     *   which would make the parent->child invocation fail. The value is an "i/n"
     *   spec (e.g. "2/4"); the placeholder is <spec> rather than <i/n> because
     *   WP-CLI considers a "/" in a synopsis token invalid. When set, this
     *   process behaves as a single shard: it builds the full file list exactly
     *   as the parent does, then converts only the files whose root-relative path
     *   hashes into shard i of n (crc32(path) % n). The parent uses this to split
     *   work across the children it spawns. Shards are disjoint by construction
     *   (each path maps to exactly one shard), and the Phase 1.1 per-file locks
     *   provide a second line of defense so even overlapping ad-hoc runs stay
     *   safe.
     *
     * @when after_wp_load
     */
    public function convert($args, $assoc_args)
    {
        $shardSpec = isset($assoc_args['shard']) ? $assoc_args['shard'] : null;
        $procsArg  = isset($assoc_args['procs']) ? $assoc_args['procs'] : null;

        if ($shardSpec !== null && $procsArg !== null) {
            \WP_CLI::error('--procs and --shard cannot be combined (--shard is internal orchestration plumbing).');
        }

        $shardIndex = null;
        $shardTotal = null;
        if ($shardSpec !== null) {
            try {
                list($shardIndex, $shardTotal) = ShardFilter::parseSpec((string) $shardSpec);
            } catch (\InvalidArgumentException $e) {
                \WP_CLI::error($e->getMessage());
            }
        }

        $isChild = ($shardIndex !== null);

        $logPrefix = $isChild ? ('[shard ' . $shardIndex . '/' . $shardTotal . '] ') : '';

        $config = Config::loadConfigAndFix();
        $override = [];

        if (isset($assoc_args['quality'])) {
            $override['max-quality'] = intval($assoc_args['quality']);
            $override['png-quality'] = intval($assoc_args['quality']);
        }
        if (isset($assoc_args['near-lossless'])) {
            $override['png-near-lossless'] = intval($assoc_args['near-lossless']);
            $override['jpeg-near-lossless'] = intval($assoc_args['near-lossless']);
        }
        if (isset($assoc_args['alpha-quality'])) {
            $override['alpha-quality'] = intval($assoc_args['alpha-quality']);
        }
        if (isset($assoc_args['encoding'])) {
            if (!in_array($assoc_args['encoding'], ['auto', 'lossy', 'lossless'])) {
                \WP_CLI::error('encoding must be auto, lossy or lossless');
            }
            $override['png-encoding'] = $assoc_args['encoding'];
            $override['jpeg-encoding'] = $assoc_args['encoding'];
        }
        if (isset($assoc_args['converter'])) {
            if (!in_array($assoc_args['converter'], ConvertersHelper::getDefaultConverterNames())) {
                \WP_CLI::error(
                  '"' . $assoc_args['converter'] . '" is not a valid converter id. ' .
                  'Valid converters are: ' . implode(', ', ConvertersHelper::getDefaultConverterNames())
                );
            }
        }

        $config = array_merge($config, $override);

        $enabledFormats = Config::enabledFormatIds($config);
        $activeFormats = $enabledFormats;
        if (isset($assoc_args['format']) && $assoc_args['format'] !== '') {
            $requested = strtolower((string) $assoc_args['format']);
            if (!in_array($requested, OutputFormat::ids(), true)) {
                \WP_CLI::error(
                    '"' . $assoc_args['format'] . '" is not a valid format. ' .
                    'Valid formats are: ' . implode(', ', OutputFormat::ids())
                );
            }
            if (!in_array($requested, $enabledFormats, true)) {
                \WP_CLI::error(
                    'The "' . $requested . '" format is not enabled in the settings. ' .
                    'Enabled formats: ' . implode(', ', $enabledFormats)
                );
            }
            $activeFormats = [$requested];
        }

        if (!$isChild) {
            \WP_CLI::log('Converting with the following settings:');
            \WP_CLI::log('- Lossless quality: ' . $config['png-quality'] . ' for PNG, ' . $config['max-quality'] . " for jpeg");
            \WP_CLI::log(
                '- Near lossless: ' .
                ($config['png-enable-near-lossless'] ? $config['png-near-lossless'] : 'disabled') . ' for PNG, ' .
                ($config['jpeg-enable-near-lossless'] ? $config['jpeg-near-lossless'] : 'disabled') . ' for jpeg, '
            );
            \WP_CLI::log('- Alpha quality: ' . $config['alpha-quality']);
            \WP_CLI::log('- Encoding: ' . $config['png-encoding'] . ' for PNG, ' . $config['jpeg-encoding'] . " for jpeg");

            if (count($override) == 0) {
                \WP_CLI::log('Note that you can override these with --quality=<quality>, etc');
            }
            \WP_CLI::log('');
        }

        $reconvert  = isset($assoc_args['reconvert']);
        $skipIfFresh = !$reconvert;

        $listOptions = BulkConvert::defaultListOptions($config);
        if ($reconvert) {
            $listOptions['filter']['only-unconverted'] = false;
        }
        if (isset($assoc_args['only-png'])) {
            $listOptions['filter']['image-types'] = 2;
        }
        if (isset($assoc_args['only-jpeg'])) {
            $listOptions['filter']['image-types'] = 1;
        }

        if (!isset($args[0])) {
          $groups = BulkConvert::getList($config, $listOptions);
          if (!$isChild) {
              foreach($groups as $group){
                  \WP_CLI::log($group['groupName'] . ' contains ' . count($group['files']) . ' ' .
                  ($reconvert ? '' : 'unconverted ') .
                  'files');
              }
              \WP_CLI::log('');
          }
        } else {
          $location = $args[0];
          if (strpos($location, '/') === 0) {
              $location = substr($location, 1);
          }
          if (strpos($location, '/') === false) {
              $rootId = $location;
              $path = '.';
          } else {
              list($rootId, $path) = explode('/', $location, 2);
          }

          if (!in_array($rootId, Paths::getImageRootIds())) {
              \WP_CLI::error(
                '"' . $args[0] . '" is not a valid image root. ' .
                'Valid roots are: ' . implode(', ', Paths::getImageRootIds())
              );
          }

          $root = Paths::getAbsDirById($rootId) . '/' . $path;
          if (!file_exists($root)) {
            \WP_CLI::error(
              '"' . $args[0] . '" does not exist. '
            );
          }
          $listOptions['root'] = $root;
          $groups = [
              [
                  'groupName' => $args[0],
                  'root' => $root,
                  'files' => BulkConvert::getListRecursively('.', $listOptions)
              ]
          ];
          if (!$isChild && count($groups[0]['files']) == 0) {
            \WP_CLI::log('Nothing to convert in ' . $args[0]);
          }
        }

        if ($isChild) {
            foreach ($groups as &$group) {
                $kept = [];
                foreach ($group['files'] as $file) {
                    $relPath = is_array($file) ? $file['path'] : $file;
                    $key = $group['groupName'] . '/' . $relPath;
                    if (ShardFilter::belongs($key, $shardIndex, $shardTotal)) {
                        $kept[] = $file;
                    }
                }
                $group['files'] = $kept;
            }
            unset($group);
        }

        $totalFiles = 0;
        foreach ($groups as $group) {
            $totalFiles += count($group['files']);
        }

        if (!$isChild) {
            $procs = self::decideProcs($procsArg, $totalFiles);

            if ($procs > 1) {
                $exitCode = self::runParallel($procs, $totalFiles, $args, $assoc_args, $activeFormats);
                if ($exitCode !== false) {
                    \WP_CLI::halt($exitCode);
                    return;
                }
            } else {
                self::announceSequential($totalFiles, $activeFormats);
            }
        }

        $converter = null;
        $convertOptions = null;

        if (isset($assoc_args['converter'])) {

            $converter = $assoc_args['converter'];
            $convertOptions = Config::generateWodOptionsFromConfigObj($config)['webp-convert']['convert'];

            // find the converter
            $optionsForThisConverter = null;
            foreach ($convertOptions['converters'] as $c) {
                if ($c['converter'] == $converter) {
                    $optionsForThisConverter = (isset($c['options']) ? $c['options'] : []);
                    break;
                }
            }
            if (!is_array($optionsForThisConverter)) {
                \WP_CLI::error('Failed handling options');
            }

            $convertOptions = array_merge($convertOptions, $optionsForThisConverter);
            unset($convertOptions['converters']);
        }

        $orgTotalFilesize = 0;
        $webpTotalFilesize = 0;
        $convertedCount = 0;
        $failedCount = 0;
        $perFormat = [];
        foreach ($activeFormats as $fmtId) {
            $perFormat[$fmtId] = ['converted' => 0, 'failed' => 0, 'org_bytes' => 0, 'out_bytes' => 0];
        }
        $multiFormat = (count($activeFormats) > 1);

        foreach($groups as $group){
            if (count($group['files']) == 0) continue;

            \WP_CLI::log($logPrefix . 'Converting ' . count($group['files']) . ' files in ' . $group['groupName']);
            \WP_CLI::log($logPrefix . '------------------------------');

            $files = array_reverse($group['files']);
            foreach($files as $key => $file)
            {
                $relPath = is_array($file) ? $file['path'] : $file;
                $fileFormats = (is_array($file) && isset($file['formats']) && is_array($file['formats']))
                    ? array_values(array_intersect($activeFormats, $file['formats']))
                    : $activeFormats;

                $path = trailingslashit($group['root']) . $relPath;
                \WP_CLI::log($logPrefix . 'Converting: ' . $relPath);

                foreach ($fileFormats as $fmtId) {
                    $formatTag = $multiFormat ? ('[' . self::formatLabel($fmtId) . '] ') : '';

                    $result = Convert::convertFile($path, $config, $convertOptions, $converter, $skipIfFresh, $fmtId);

                    if ($result['success']) {
                        $convertedCount++;
                        $orgSize = $result['filesize-original'];
                        $outSize = $result['filesize-webp'];

                        $orgTotalFilesize += $orgSize;
                        $webpTotalFilesize += $outSize;
                        $perFormat[$fmtId]['converted']++;
                        $perFormat[$fmtId]['org_bytes'] += $orgSize;
                        $perFormat[$fmtId]['out_bytes'] += $outSize;

                        $percentage = ($orgSize == 0 ? 100 : round(($outSize/$orgSize) * 100));

                        \WP_CLI::log(
                            \WP_CLI::colorize(
                                $logPrefix . $formatTag . "%GOK%n. " .
                                "Size: " .
                                ($percentage<90 ? "%G" : ($percentage<100 ? "%Y" : "%R")) .
                                $percentage .
                                "% %nof original" .
                                " (" . self::printableSize($orgSize) . ' => ' . self::printableSize($outSize) .
                                ") "
                            )
                        );
                    } else {
                        $failedCount++;
                        $perFormat[$fmtId]['failed']++;
                        \WP_CLI::log(
                            \WP_CLI::colorize($logPrefix . $formatTag . "%RConversion failed. " . $result['msg'] . "%n")
                        );
                    }
                }
            }
        }

        if ($orgTotalFilesize > 0) {
          $percentage = ($orgTotalFilesize == 0 ? 100 : round(($webpTotalFilesize/$orgTotalFilesize) * 100));
          \WP_CLI::log(
              \WP_CLI::colorize(
                  $logPrefix . "Done. " .
                  "Size of converted images: " .
                  ($percentage<90 ? "%G" : ($percentage<100 ? "%Y" : "%R")) .
                  $percentage .
                  "% %nof original" .
                  " (" . self::printableSize($orgTotalFilesize) . ' => ' . self::printableSize($webpTotalFilesize) .
                  ") "
              )
          );
          if ($multiFormat) {
              foreach ($activeFormats as $fmtId) {
                  $f = $perFormat[$fmtId];
                  if ($f['converted'] === 0 && $f['failed'] === 0) { continue; }
                  $fp = ($f['org_bytes'] == 0) ? 100 : round(($f['out_bytes'] / $f['org_bytes']) * 100);
                  \WP_CLI::log(
                      $logPrefix . '  ' . self::formatLabel($fmtId) . ': ' . $f['converted'] . ' converted' .
                      ($f['failed'] > 0 ? (', ' . $f['failed'] . ' failed') : '') .
                      ($f['org_bytes'] > 0 ? (' (' . $fp . '% of original)') : '')
                  );
              }
          }
        }

        if ($isChild) {
            $summary = [
                'shard'     => $shardIndex,
                'total'     => $shardTotal,
                'converted' => $convertedCount,
                'failed'    => $failedCount,
                'org_bytes' => $orgTotalFilesize,
                'webp_bytes'=> $webpTotalFilesize,
                'formats'   => $perFormat,
            ];
            \WP_CLI::log(self::SUMMARY_PREFIX . json_encode($summary));

            if ($failedCount > 0) {
                \WP_CLI::halt(1);
            }
        }
    }

    /**
     * @param  array<int,array>  $summaries
     * @return array{converted:int,failed:int,org_bytes:int,webp_bytes:int,formats:array<string,array>}
     */
    public static function aggregateSummaries(array $summaries)
    {
        $agg = ['converted' => 0, 'failed' => 0, 'org_bytes' => 0, 'webp_bytes' => 0, 'formats' => []];
        foreach ($summaries as $summary) {
            if (!is_array($summary)) {
                continue;
            }
            $agg['converted']  += isset($summary['converted']) ? (int) $summary['converted'] : 0;
            $agg['failed']     += isset($summary['failed']) ? (int) $summary['failed'] : 0;
            $agg['org_bytes']  += isset($summary['org_bytes']) ? (int) $summary['org_bytes'] : 0;
            $agg['webp_bytes'] += isset($summary['webp_bytes']) ? (int) $summary['webp_bytes'] : 0;

            if (isset($summary['formats']) && is_array($summary['formats'])) {
                foreach ($summary['formats'] as $fmtId => $f) {
                    if (!isset($agg['formats'][$fmtId])) {
                        $agg['formats'][$fmtId] = ['converted' => 0, 'failed' => 0, 'org_bytes' => 0, 'out_bytes' => 0];
                    }
                    $agg['formats'][$fmtId]['converted'] += isset($f['converted']) ? (int) $f['converted'] : 0;
                    $agg['formats'][$fmtId]['failed']    += isset($f['failed']) ? (int) $f['failed'] : 0;
                    $agg['formats'][$fmtId]['org_bytes'] += isset($f['org_bytes']) ? (int) $f['org_bytes'] : 0;
                    $agg['formats'][$fmtId]['out_bytes'] += isset($f['out_bytes']) ? (int) $f['out_bytes'] : 0;
                }
            }
        }
        return $agg;
    }

    /**
     *  @return int
     */
    private static function decideProcs($procsArg, $totalFiles)
    {
        if ($totalFiles <= 0) {
            return 1;
        }

        if ($procsArg !== null) {
            $procs = (int) $procsArg;
            if ($procs < 1) {
                \WP_CLI::error('--procs must be a positive integer.');
            }
            if ($procs > self::PROCS_HARD_CAP) {
                \WP_CLI::warning(
                    '--procs=' . $procs . ' exceeds the maximum of ' . self::PROCS_HARD_CAP .
                    '; using ' . self::PROCS_HARD_CAP . '.'
                );
                $procs = self::PROCS_HARD_CAP;
            }
            return min($procs, max(1, $totalFiles));
        }

        if ($totalFiles < self::PARALLEL_MIN_FILES) {
            return 1;
        }

        $recommended = (new ConcurrencyAdvisor())->recommendedCliProcs();
        if ($recommended <= 1) {
            return 1;
        }

        return min($recommended, $totalFiles);
    }

    private static function announceSequential($totalFiles, $activeFormats = ['webp'])
    {
        if ($totalFiles <= 0) {
            return;
        }
        $reason = ($totalFiles < self::PARALLEL_MIN_FILES) ? 'small batch' : 'single core available';
        \WP_CLI::log(
            'Converting ' . number_format($totalFiles) . ' files to ' . self::formatsLabel($activeFormats) .
            ' sequentially (' . $reason . ')...'
        );
        \WP_CLI::log('');
    }

    /**
     *  @return int|false
     */
    private static function runParallel($procs, $totalFiles, $args, $assoc_args, $activeFormats = ['webp'])
    {
        if (!function_exists('proc_open') || !function_exists('proc_close')) {
            \WP_CLI::warning('proc_open is unavailable (disabled?); converting sequentially.');
            return false;
        }

        $childArgv = self::buildChildCommand($args, $assoc_args);
        if ($childArgv === false) {
            \WP_CLI::warning('Could not determine how to re-invoke wp-cli; converting sequentially.');
            return false;
        }

        $cores = (new ConcurrencyAdvisor())->cpuCoreCount();
        \WP_CLI::log(
            'Converting ' . number_format($totalFiles) . ' files to ' . self::formatsLabel($activeFormats) .
            ' using ' . $procs . ' parallel processes (' . $cores . ' CPU cores detected)...'
        );
        \WP_CLI::log('');

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $children = [];
        for ($i = 1; $i <= $procs; $i++) {
            $cmd = self::shellJoin(array_merge($childArgv, ['--shard=' . $i . '/' . $procs]));

            $pipes = [];
            $proc = @proc_open($cmd, $descriptors, $pipes);
            if (!is_resource($proc)) {
                \WP_CLI::warning('Failed to spawn shard ' . $i . '/' . $procs . '; converting sequentially.');
                self::reapChildren($children);
                return false;
            }
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $children[$i] = [
                'proc'  => $proc,
                'pipes' => $pipes,
                'buf'   => [1 => '', 2 => ''],
                'summary' => null,
            ];
        }

        $open = true;
        while ($open) {
            $read = [];
            $map = [];
            foreach ($children as $shard => &$child) {
                foreach ([1, 2] as $fd) {
                    if (is_resource($child['pipes'][$fd])) {
                        $read[] = $child['pipes'][$fd];
                        $map[(int) $child['pipes'][$fd]] = [$shard, $fd];
                    }
                }
            }
            unset($child);

            if (count($read) === 0) {
                break;
            }

            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, 0, 200000);
            if ($ready === false) {
                break;
            }
            if ($ready === 0) {
                continue;
            }

            foreach ($read as $stream) {
                $key = (int) $stream;
                if (!isset($map[$key])) {
                    continue;
                }
                list($shard, $fd) = $map[$key];
                $chunk = fread($stream, 65536);
                if ($chunk === '' || $chunk === false) {
                    if (feof($stream)) {
                        self::flushChildLines($children[$shard], $shard, $procs, $fd, true);
                        fclose($stream);
                        $children[$shard]['pipes'][$fd] = null;
                    }
                    continue;
                }
                $children[$shard]['buf'][$fd] .= $chunk;
                self::flushChildLines($children[$shard], $shard, $procs, $fd, false);
            }

            $open = false;
            foreach ($children as $child) {
                if (is_resource($child['pipes'][1]) || is_resource($child['pipes'][2])) {
                    $open = true;
                    break;
                }
            }
        }

        $anyFailed = false;
        $summaries = [];

        foreach ($children as $shard => &$child) {
            foreach ([1, 2] as $fd) {
                if ($child['buf'][$fd] !== '') {
                    self::emitChildLine($shard, $procs, $fd, $child['buf'][$fd]);
                    $child['buf'][$fd] = '';
                }
            }
            $status = proc_close($child['proc']);
            if ($status !== 0) {
                $anyFailed = true;
            }
            if (is_array($child['summary'])) {
                $summaries[] = $child['summary'];
            } else {
                $anyFailed = true;
            }
        }
        unset($child);

        $agg = self::aggregateSummaries($summaries);
        if ($agg['failed'] > 0) {
            $anyFailed = true;
        }

        \WP_CLI::log('');
        \WP_CLI::log('------------------------------');
        $pct = ($agg['org_bytes'] == 0) ? 100 : round(($agg['webp_bytes'] / $agg['org_bytes']) * 100);
        \WP_CLI::log(
            \WP_CLI::colorize(
                'All shards done. Converted ' . number_format($agg['converted']) . ' images' .
                ($agg['failed'] > 0 ? (', %R' . number_format($agg['failed']) . ' failed%n') : '') .
                ($agg['org_bytes'] > 0
                    ? ('. Size of converted images: ' . $pct . '% of original (' .
                       self::printableSize($agg['org_bytes']) . ' => ' . self::printableSize($agg['webp_bytes']) . ')')
                    : '')
            )
        );

        if (count($activeFormats) > 1 && !empty($agg['formats'])) {
            foreach ($activeFormats as $fmtId) {
                if (!isset($agg['formats'][$fmtId])) { continue; }
                $f = $agg['formats'][$fmtId];
                if ((int) $f['converted'] === 0 && (int) $f['failed'] === 0) { continue; }
                $fp = ($f['org_bytes'] == 0) ? 100 : round(($f['out_bytes'] / $f['org_bytes']) * 100);
                \WP_CLI::log(
                    '  ' . self::formatLabel($fmtId) . ': ' . number_format($f['converted']) . ' converted' .
                    ($f['failed'] > 0 ? (', ' . number_format($f['failed']) . ' failed') : '') .
                    ($f['org_bytes'] > 0 ? (' (' . $fp . '% of original)') : '')
                );
            }
        }

        return $anyFailed ? 1 : 0;
    }

    private static function flushChildLines(&$child, $shard, $total, $fd, $final)
    {
        $buf = &$child['buf'][$fd];
        while (($nl = strpos($buf, "\n")) !== false) {
            $line = substr($buf, 0, $nl);
            $buf = substr($buf, $nl + 1);
            $line = rtrim($line, "\r");

            if ($fd === 1 && strpos($line, self::SUMMARY_PREFIX) === 0) {
                $json = substr($line, strlen(self::SUMMARY_PREFIX));
                $decoded = json_decode($json, true);
                if (is_array($decoded)) {
                    $child['summary'] = $decoded;
                }
                continue;
            }
            self::emitChildLine($shard, $total, $fd, $line);
        }
        if ($final && $buf !== '') {
            if (!($fd === 1 && strpos($buf, self::SUMMARY_PREFIX) === 0)) {
                self::emitChildLine($shard, $total, $fd, $buf);
            } else {
                $decoded = json_decode(substr($buf, strlen(self::SUMMARY_PREFIX)), true);
                if (is_array($decoded)) {
                    $child['summary'] = $decoded;
                }
            }
            $buf = '';
        }
    }

    private static function emitChildLine($shard, $total, $fd, $line)
    {
        if ($line === '') {
            \WP_CLI::log('');
            return;
        }
        if ($fd === 2) {
            \WP_CLI::warning('[shard ' . $shard . '/' . $total . '] ' . $line);
            return;
        }
        \WP_CLI::log($line);
    }

    private static function reapChildren(&$children)
    {
        foreach ($children as $child) {
            foreach ([1, 2] as $fd) {
                if (isset($child['pipes'][$fd]) && is_resource($child['pipes'][$fd])) {
                    @fclose($child['pipes'][$fd]);
                }
            }
            if (isset($child['proc']) && is_resource($child['proc'])) {
                @proc_terminate($child['proc']);
                @proc_close($child['proc']);
            }
        }
    }

    /**
     *  @return array<int,string>|false
     */
    private static function buildChildCommand($args, $assoc_args)
    {
        $wpEntry = isset($GLOBALS['argv'][0]) ? $GLOBALS['argv'][0] : null;
        if (!is_string($wpEntry) || $wpEntry === '') {
            return false;
        }
        $php = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php';

        $argv = [$php, $wpEntry, 'magic-convert', 'convert'];

        return self::buildChildArgs($argv, $args, $assoc_args);
    }

    private static function buildChildArgs($argv, $args, $assoc_args)
    {
        if (isset($args[0]) && $args[0] !== '') {
            $argv[] = (string) $args[0];
        }

        foreach (['reconvert', 'only-png', 'only-jpeg'] as $flag) {
            if (isset($assoc_args[$flag]) && $assoc_args[$flag] !== false) {
                $argv[] = '--' . $flag;
            }
        }

        foreach (['quality', 'near-lossless', 'alpha-quality', 'encoding', 'converter', 'format'] as $flag) {
            if (isset($assoc_args[$flag]) && $assoc_args[$flag] !== '' && $assoc_args[$flag] !== false) {
                $argv[] = '--' . $flag . '=' . $assoc_args[$flag];
            }
        }

        $globals = self::wpCliGlobalArgs();
        foreach (['path', 'url'] as $g) {
            if (isset($globals[$g]) && $globals[$g] !== '' && $globals[$g] !== false) {
                $argv[] = '--' . $g . '=' . $globals[$g];
            }
        }

        return $argv;
    }

    /**
     *  @return array<string,mixed>
     */
    private static function wpCliGlobalArgs()
    {
        if (!class_exists('\WP_CLI') || !method_exists('\WP_CLI', 'get_runner')) {
            return [];
        }
        try {
            $runner = \WP_CLI::get_runner();
        } catch (\Throwable $e) {
            return [];
        }
        if (!is_object($runner) || !isset($runner->config) || !is_array($runner->config)) {
            return [];
        }
        return $runner->config;
    }

    private static function shellJoin(array $argv)
    {
        return implode(' ', array_map('escapeshellarg', $argv));
    }

    /**
     *  Flush webps
     *
     *  ## OPTIONS
     *  [--only-png]
     *  : Only flush webps that are conversions of a PNG)
     */
    public function flushwebp($args, $assoc_args)
    {
        $config = Config::loadConfigAndFix();

        $onlyPng = isset($assoc_args['only-png']);

        if ($onlyPng) {
            \WP_CLI::log('Flushing webp files that are conversions of PNG images');
        } else {
            \WP_CLI::log('Flushing all webp files');
        }

        $result = CachePurge::purge($config, $onlyPng);

        \WP_CLI::log(
          \WP_CLI::colorize("%GFlushed " . $result['delete-count'] . " webp files%n")
        );
        if ($result['fail-count'] > 0) {
          \WP_CLI::log(
            \WP_CLI::colorize("%RFailed deleting " . $result['fail-count'] . " webp files%n")
          );
        }
    }


}
