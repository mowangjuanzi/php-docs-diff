<?php

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\SingleCommandApplication;

require __DIR__ . "/vendor/autoload.php";

const EN_DIR = __DIR__ . "/../en/";
const ZH_DIR = __DIR__ . "/../zh/";

(new SingleCommandApplication())
    ->setName("docs-diff")
    ->setVersion("0.1.0")
    ->addOption("missing", 'm', InputOption::VALUE_NEGATABLE, "是否忽略未翻译的")
    ->addArgument("dir", InputArgument::IS_ARRAY, "目录/文件名称，如果不指定则为全部", [])
    ->setCode(function (InputInterface $input, OutputInterface $output) {
        $dirs = $input->getArgument("dir");
        $dirs = $dirs ?: ["."];

        // 忽略未翻译的
        $flag_miss = $input->getOption('missing');

        foreach ($dirs as $dir) {
            $en_full_dir = EN_DIR . $dir;

            // 判断指定的文件或者目录是否存在
            if (!file_exists($en_full_dir)) {
                throw new Exception("$en_full_dir 不存在");
            }

            // 如果是文件就讲目录和文件名进行分解
            $file = '';
            if (is_file($en_full_dir)) {
                $en_full_dir = pathinfo($en_full_dir);
                $file = $en_full_dir['basename'];
                $en_full_dir = $en_full_dir['dirname'];
            }

            // 循环指定目录
            $directory = new RecursiveDirectoryIterator($en_full_dir, FilesystemIterator::KEY_AS_PATHNAME | FilesystemIterator::CURRENT_AS_FILEINFO | FilesystemIterator::SKIP_DOTS);
            $iterator = new RecursiveIteratorIterator($directory, RecursiveIteratorIterator::CHILD_FIRST);

            /** @var SplFileInfo $item */
            foreach ($iterator as $item) {
                // 如果不是目录就跳过
                if (!$item->isFile()) {
                    continue;
                }

                // 判断扩展名
                if (!in_array($item->getExtension(), ['xml', 'ant'])) {
                    continue;
                }

                // 如果需要查询的文件是指定文件，那么就跳过所有跟该文件无关的文件
                if ($file && $file != $item->getBasename()) {
                    continue;
                }

                $pathname = $item->getPathname();

                $zh_item = str_replace(EN_DIR, ZH_DIR, $pathname);

                // 过滤不需要翻译的文件
                if (!is_translatable($item->getFilename())  && !str_ends_with($item->getPath(), 'appendices/migration56')) {

                    // 判断是否在中文版本存在
//                    if (file_exists($zh_item)) {
//                        $output->writeln("删除: " . str_replace(ZH_DIR, '', $zh_item));
//                    }

                    continue;
                }

                // 判断中文版本是否存在，如果不存在则提示缺失
                if (!file_exists($zh_item)) {
                    if (!$flag_miss) {
                        $output->writeln("缺失: " . str_replace(ZH_DIR, '', $zh_item));
                    }
                    continue;
                }

                // 对比中英版本号是否一致
                $en_commit = trim(shell_exec("git -C {$item->getPath()} log -1 --pretty=%H ./{$item->getFilename()}"));
                $regex = "'<!--\s*EN-Revision:\s*(.+)\s*Maintainer:\s*(.+)\s*Status:\s*(.+)\s*-->'U";
                preg_match ($regex, file_get_contents($zh_item), $zh_commit);
                $zh_commit = trim($zh_commit[1] ?? '');

                if ($en_commit != $zh_commit) {
                    $output->writeln("翻译: " . str_replace(ZH_DIR, '', $zh_item) . " $zh_commit");
                }
            }
        }
    })->run();
