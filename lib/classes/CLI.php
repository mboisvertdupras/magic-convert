<?php

namespace MagicConvert;

class CLI extends \WP_CLI_Command
{

    /** Minimum backlog size before automatic parallelism kicks in. Below this,
     *  the per-process WP bootstrap overhead is not worth paying. */
    const PARALLEL_MIN_FILES = 50;

    /** Hard cap on --procs, even if a power user asks for more. Coarse shards
     *  only; more processes than this just thrash the disk and the scheduler. */
    const PROCS_HARD_CAP = 16;

    /** Machine-readable per-shard summary line a child prints right before it
     *  exits, so the parent can aggregate converted/failed counts and sizes.
     *  Format: this prefix followed by a single JSON object. */
    const SUMMARY_PREFIX = '#MC-SUMMARY ';

    private static function printableSize($bytes) {
        return ($bytes < 10000) ? $bytes . " bytes" : round($bytes / 1024) . ' kb';
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
        // ---------------------------------------------------------------------
        // 0. Parse the two orchestration flags (--procs / --shard) up front and
        //    decide which "mode" this invocation is running in:
        //      - CHILD  : we were given --shard=i/n; convert only our shard.
        //      - PARENT : no --shard; we may decide to spawn children.
        //    --procs and --shard are mutually exclusive (a child is told its
        //    shard explicitly; --procs only makes sense for the parent).
        // ---------------------------------------------------------------------
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

        // A label printed in front of every progress line in a child, so
        // interleaved child output in the parent's terminal stays readable.
        $logPrefix = $isChild ? ('[shard ' . $shardIndex . '/' . $shardTotal . '] ') : '';

        // ---------------------------------------------------------------------
        // 1. Build config + overrides (identical in parent and children, so the
        //    file list they each derive is identical — a precondition for the
        //    shard partition to be a correct, complete cover).
        // ---------------------------------------------------------------------
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

        // The "settings" banner is noise when many children print it at once;
        // only the parent / a plain sequential run prints it.
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

        // ---------------------------------------------------------------------
        // 2. Idempotency / "skip if fresh".
        //    Sharded/parallel runs are idempotent resumes by default: a child
        //    that re-encounters an already-fresh destination skips it cheaply.
        //    --reconvert disables that (and also widens the file list to include
        //    already-converted files).
        // ---------------------------------------------------------------------
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

        // ---------------------------------------------------------------------
        // 3. Build the file list (grouped by image root), exactly as before.
        // ---------------------------------------------------------------------
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

        // ---------------------------------------------------------------------
        // 4. If we are a CHILD, keep only the files that belong to our shard.
        //    The key fed to ShardFilter is the GROUP-QUALIFIED root-relative
        //    path ("<groupName>/<file>"). Qualifying with the group name makes
        //    the key globally unique across roots (two roots could otherwise
        //    contain the same relative path) while remaining a stable,
        //    location-independent string that the parent would compute the same
        //    way. Files are kept in the SAME order in every process, so the
        //    partition is identical everywhere.
        // ---------------------------------------------------------------------
        if ($isChild) {
            foreach ($groups as &$group) {
                $kept = [];
                foreach ($group['files'] as $file) {
                    $key = $group['groupName'] . '/' . $file;
                    if (ShardFilter::belongs($key, $shardIndex, $shardTotal)) {
                        $kept[] = $file;
                    }
                }
                $group['files'] = $kept;
            }
            unset($group);
        }

        // Total backlog across all groups (post-shard-filtering).
        $totalFiles = 0;
        foreach ($groups as $group) {
            $totalFiles += count($group['files']);
        }

        // ---------------------------------------------------------------------
        // 5. PARENT decision: parallelize or run inline?
        //    Only the parent (no --shard) ever fans out. A child always converts
        //    its assigned files inline.
        // ---------------------------------------------------------------------
        if (!$isChild) {
            $procs = self::decideProcs($procsArg, $totalFiles);

            if ($procs > 1) {
                // Attempt the parallel fan-out. On any failure it returns false
                // and we fall through to the inline sequential path below
                // (GRACEFUL FALLBACK: a run must never fail merely because
                // parallelism was impossible).
                $exitCode = self::runParallel($procs, $totalFiles, $args, $assoc_args);
                if ($exitCode !== false) {
                    \WP_CLI::halt($exitCode);
                    return;
                }
                // else: fall through to inline sequential.
            } else {
                self::announceSequential($totalFiles);
            }
        }

        // ---------------------------------------------------------------------
        // 6. Inline sequential conversion (used by: every child, a forced
        //    --procs=1 / small-batch parent, and the graceful fallback).
        // ---------------------------------------------------------------------
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

        foreach($groups as $group){
            if (count($group['files']) == 0) continue;

            \WP_CLI::log($logPrefix . 'Converting ' . count($group['files']) . ' files in ' . $group['groupName']);
            \WP_CLI::log($logPrefix . '------------------------------');

            $files = array_reverse($group['files']);
            foreach($files as $key => $file)
            {
                $path = trailingslashit($group['root']) . $file;
                \WP_CLI::log($logPrefix . 'Converting: ' . $file);

                $result = Convert::convertFile($path, $config, $convertOptions, $converter, $skipIfFresh);

                if ($result['success']) {
                    $convertedCount++;
                    $orgSize = $result['filesize-original'];
                    $webpSize = $result['filesize-webp'];

                    $orgTotalFilesize += $orgSize;
                    $webpTotalFilesize += $webpSize;

                    $percentage = ($orgSize == 0 ? 100 : round(($webpSize/$orgSize) * 100));

                    \WP_CLI::log(
                        \WP_CLI::colorize(
                            $logPrefix . "%GOK%n. " .
                            "Size: " .
                            ($percentage<90 ? "%G" : ($percentage<100 ? "%Y" : "%R")) .
                            $percentage .
                            "% %nof original" .
                            " (" . self::printableSize($orgSize) . ' => ' . self::printableSize($webpSize) .
                            ") "
                        )
                    );
                } else {
                    $failedCount++;
                    \WP_CLI::log(
                        \WP_CLI::colorize($logPrefix . "%RConversion failed. " . $result['msg'] . "%n")
                    );
                }
            }
        }

        if ($orgTotalFilesize > 0) {
          $percentage = ($orgTotalFilesize == 0 ? 100 : round(($webpTotalFilesize/$orgTotalFilesize) * 100));
          \WP_CLI::log(
              \WP_CLI::colorize(
                  $logPrefix . "Done. " .
                  "Size of webps: " .
                  ($percentage<90 ? "%G" : ($percentage<100 ? "%Y" : "%R")) .
                  $percentage .
                  "% %nof original" .
                  " (" . self::printableSize($orgTotalFilesize) . ' => ' . self::printableSize($webpTotalFilesize) .
                  ") "
              )
          );
        }

        // A child emits a machine-readable summary line as its very last output,
        // so the parent can aggregate per-shard results without re-parsing the
        // human log. (The parent itself never prints this.)
        if ($isChild) {
            $summary = [
                'shard'     => $shardIndex,
                'total'     => $shardTotal,
                'converted' => $convertedCount,
                'failed'    => $failedCount,
                'org_bytes' => $orgTotalFilesize,
                'webp_bytes'=> $webpTotalFilesize,
            ];
            \WP_CLI::log(self::SUMMARY_PREFIX . json_encode($summary));

            if ($failedCount > 0) {
                \WP_CLI::halt(1);
            }
        }
    }

    /**
     *  Decide how many processes the parent should use.
     *
     *  - Explicit --procs wins (validated, capped at PROCS_HARD_CAP with a
     *    warning, floored at 1). --procs=1 means "force sequential".
     *  - Otherwise it is automatic: parallelize only when the backlog is large
     *    enough to amortize the per-process WP bootstrap AND the resource-aware
     *    ConcurrencyAdvisor (committed in Phase 1.2 — reused here, never
     *    re-implemented) recommends more than one CLI proc. The recommended
     *    proc count is itself capped at the backlog (no point spawning more
     *    children than there are files).
     *
     *  @return int  >= 1. A return of 1 means "run sequentially inline".
     */
    private static function decideProcs($procsArg, $totalFiles)
    {
        if ($totalFiles <= 0) {
            return 1;
        }

        // Explicit override.
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

        // Automatic.
        if ($totalFiles < self::PARALLEL_MIN_FILES) {
            return 1;
        }

        $recommended = (new ConcurrencyAdvisor())->recommendedCliProcs();
        if ($recommended <= 1) {
            return 1;
        }

        return min($recommended, $totalFiles);
    }

    /** Print the one-line "running sequentially" explanation. */
    private static function announceSequential($totalFiles)
    {
        if ($totalFiles <= 0) {
            return;
        }
        $reason = ($totalFiles < self::PARALLEL_MIN_FILES) ? 'small batch' : 'single core available';
        \WP_CLI::log(
            'Converting ' . number_format($totalFiles) . ' files sequentially (' . $reason . ')...'
        );
        \WP_CLI::log('');
    }

    /**
     *  Spawn $procs child processes of THIS wp-cli command, one per shard, and
     *  stream their output back with shard prefixes.
     *
     *  Each child is invoked as the same `wp magic-convert convert` command with
     *  `--shard=i/n` added and every relevant user flag forwarded (see
     *  buildChildArgs). The parent reads all children's stdout/stderr
     *  non-blockingly in a select loop, line-buffers them, and re-emits each
     *  line. When every child has exited it prints an aggregate summary parsed
     *  from each child's '#MC-SUMMARY {json}' line and returns an exit code
     *  (non-zero if any child failed or could not be parsed).
     *
     *  GRACEFUL FALLBACK: returns false (without having converted anything) when
     *  proc_open is unavailable or the spawn machinery cannot be set up, so the
     *  caller can run sequentially instead. A real conversion run must never be
     *  blocked just because parallelism was impossible.
     *
     *  @return int|false  Exit code (0 ok, non-zero if a child failed), or false
     *                     to signal "could not parallelize, fall back".
     */
    private static function runParallel($procs, $totalFiles, $args, $assoc_args)
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
            'Converting ' . number_format($totalFiles) . ' files using ' . $procs .
            ' parallel processes (' . $cores . ' CPU cores detected)...'
        );
        \WP_CLI::log('');

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $children = [];   // shard index => ['proc'=>res, 'pipes'=>[], 'buf'=>['1'=>'', '2'=>'']]
        for ($i = 1; $i <= $procs; $i++) {
            $cmd = self::shellJoin(array_merge($childArgv, ['--shard=' . $i . '/' . $procs]));

            $pipes = [];
            $proc = @proc_open($cmd, $descriptors, $pipes);
            if (!is_resource($proc)) {
                \WP_CLI::warning('Failed to spawn shard ' . $i . '/' . $procs . '; converting sequentially.');
                // Tear down any children already started, then fall back.
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

        // --- select loop: stream child output line-by-line ----------------------
        $open = true;
        while ($open) {
            $read = [];
            $map = [];   // (int) stream id => [shard, fd]
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
                break;  // all pipes closed
            }

            $write = null;
            $except = null;
            // 200ms tick: long enough to avoid a busy spin, short enough to feel live.
            $ready = @stream_select($read, $write, $except, 0, 200000);
            if ($ready === false) {
                break;  // interrupted; let the wait/reap below finish things off
            }
            if ($ready === 0) {
                continue;  // timeout, just loop and re-poll
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
                        // Flush any trailing partial line, then close this pipe.
                        self::flushChildLines($children[$shard], $shard, $procs, $fd, true);
                        fclose($stream);
                        $children[$shard]['pipes'][$fd] = null;
                    }
                    continue;
                }
                $children[$shard]['buf'][$fd] .= $chunk;
                self::flushChildLines($children[$shard], $shard, $procs, $fd, false);
            }

            // Are any pipes still open?
            $open = false;
            foreach ($children as $child) {
                if (is_resource($child['pipes'][1]) || is_resource($child['pipes'][2])) {
                    $open = true;
                    break;
                }
            }
        }

        // --- wait for exit + aggregate -----------------------------------------
        $anyFailed = false;
        $agg = ['converted' => 0, 'failed' => 0, 'org_bytes' => 0, 'webp_bytes' => 0];

        foreach ($children as $shard => &$child) {
            // Flush any leftover buffered output that didn't end in a newline.
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
                $agg['converted']  += (int) $child['summary']['converted'];
                $agg['failed']     += (int) $child['summary']['failed'];
                $agg['org_bytes']  += (int) $child['summary']['org_bytes'];
                $agg['webp_bytes'] += (int) $child['summary']['webp_bytes'];
            } else {
                // No machine-readable summary => treat as a failed shard so we
                // don't silently under-report.
                $anyFailed = true;
            }
            if ($agg['failed'] > 0) {
                $anyFailed = true;
            }
        }
        unset($child);

        \WP_CLI::log('');
        \WP_CLI::log('------------------------------');
        $pct = ($agg['org_bytes'] == 0) ? 100 : round(($agg['webp_bytes'] / $agg['org_bytes']) * 100);
        \WP_CLI::log(
            \WP_CLI::colorize(
                'All shards done. Converted ' . number_format($agg['converted']) . ' files' .
                ($agg['failed'] > 0 ? (', %R' . number_format($agg['failed']) . ' failed%n') : '') .
                ($agg['org_bytes'] > 0
                    ? ('. Size of webps: ' . $pct . '% of original (' .
                       self::printableSize($agg['org_bytes']) . ' => ' . self::printableSize($agg['webp_bytes']) . ')')
                    : '')
            )
        );

        return $anyFailed ? 1 : 0;
    }

    /**
     *  Emit any complete (newline-terminated) lines currently buffered for one
     *  child stream, capturing the machine-readable summary line and otherwise
     *  re-printing each line with a shard prefix. When $final is true, the
     *  trailing partial line (if any) is also flushed.
     */
    private static function flushChildLines(&$child, $shard, $total, $fd, $final)
    {
        $buf = &$child['buf'][$fd];
        while (($nl = strpos($buf, "\n")) !== false) {
            $line = substr($buf, 0, $nl);
            $buf = substr($buf, $nl + 1);
            $line = rtrim($line, "\r");

            // Capture and swallow the summary line (don't echo it to the user).
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
            // Trailing line without newline.
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

    /**
     *  Print one line of child output. Child lines already carry their own
     *  "[shard i/n] " prefix (the child sets $logPrefix), so the parent passes
     *  them through verbatim for stdout; stderr lines are routed to WP_CLI::warning.
     */
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

    /** Best-effort teardown of already-spawned children during a failed fan-out. */
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
     *  Build the argv for a child invocation (everything EXCEPT the --shard flag,
     *  which runParallel appends per child).
     *
     *  ## How the wp binary + globals are derived (robustly)
     *
     *  A child must re-run *this exact* wp-cli command. We reconstruct it from:
     *
     *    1. The PHP binary + wp entry script the parent itself was launched with:
     *       $GLOBALS['argv'][0] is wp-cli's own phar/entry path, and PHP_BINARY
     *       is the interpreter. Invoking `<php> <wp-entry> ...` re-enters wp-cli
     *       independently of how `wp` is aliased on PATH, which is the most
     *       portable approach across phar installs, Composer-global installs and
     *       distro packages.
     *    2. The command path: `magic-convert convert`.
     *    3. The user's positional <location> arg (if any) and the relevant
     *       conversion flags, forwarded verbatim.
     *    4. The WP-CLI *global* runtime args --path / --url when the parent was
     *       given them, pulled from WP_CLI::get_runner()->config, so the child
     *       bootstraps the SAME WordPress install (critical on multisite and on
     *       installs where the cwd is not the WP root).
     *
     *  @return array<int,string>|false  Argv (string per element), or false if
     *                                   the wp entry point cannot be determined.
     */
    private static function buildChildCommand($args, $assoc_args)
    {
        // --- 1. php binary + wp entry script ----------------------------------
        $wpEntry = isset($GLOBALS['argv'][0]) ? $GLOBALS['argv'][0] : null;
        if (!is_string($wpEntry) || $wpEntry === '') {
            return false;
        }
        $php = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php';

        $argv = [$php, $wpEntry, 'magic-convert', 'convert'];

        return self::buildChildArgs($argv, $args, $assoc_args);
    }

    /**
     *  Append the forwarded positional arg, conversion flags and WP-CLI globals
     *  to a child argv prefix. Split out from buildChildCommand so it is unit-
     *  reviewable and so the flag-forwarding coverage is explicit in one place.
     *
     *  Forwarded:
     *    - positional <location> (args[0]),
     *    - --reconvert, --only-png, --only-jpeg               (boolean flags),
     *    - --quality, --near-lossless, --alpha-quality,
     *      --encoding, --converter                             (value flags),
     *    - WP-CLI globals --path / --url when present.
     *  NOT forwarded:
     *    - --procs (the parent already expanded it into a shard count),
     *    - --shard (added per-child by runParallel).
     */
    private static function buildChildArgs($argv, $args, $assoc_args)
    {
        // Positional <location>.
        if (isset($args[0]) && $args[0] !== '') {
            $argv[] = (string) $args[0];
        }

        // Boolean flags.
        foreach (['reconvert', 'only-png', 'only-jpeg'] as $flag) {
            if (isset($assoc_args[$flag]) && $assoc_args[$flag] !== false) {
                $argv[] = '--' . $flag;
            }
        }

        // Value flags.
        foreach (['quality', 'near-lossless', 'alpha-quality', 'encoding', 'converter'] as $flag) {
            if (isset($assoc_args[$flag]) && $assoc_args[$flag] !== '' && $assoc_args[$flag] !== false) {
                $argv[] = '--' . $flag . '=' . $assoc_args[$flag];
            }
        }

        // WP-CLI global runtime args (--path / --url) so the child bootstraps the
        // identical WordPress install. Read from the runner config; fall back to
        // whatever the parent received in $assoc_args (WP-CLI strips globals from
        // $assoc_args, so the runner config is the reliable source).
        $globals = self::wpCliGlobalArgs();
        foreach (['path', 'url'] as $g) {
            if (isset($globals[$g]) && $globals[$g] !== '' && $globals[$g] !== false) {
                $argv[] = '--' . $g . '=' . $globals[$g];
            }
        }

        return $argv;
    }

    /**
     *  Pull the WP-CLI global runtime config (--path, --url, ...) from the
     *  runner. Defensive: if the runner / config is not introspectable in this
     *  wp-cli version, return an empty array (the child then bootstraps from the
     *  cwd, which is correct for the common single-site case).
     *
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

    /**
     *  Join an argv array into a single shell-safe command string. Every element
     *  is escapeshellarg()'d so paths/URLs with spaces or shell metacharacters
     *  survive the round-trip through proc_open's "string command" form.
     */
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
