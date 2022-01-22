<?php

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\SingleCommandApplication;

require __DIR__ . "/vendor/autoload.php";

function foreachDir(string $en_dir, string $zh_dir, string $parent_root, OutputInterface $output)
{
    $zh_file_map = [];

    if (is_dir($zh_dir)) {
        $zh_iterator = new FilesystemIterator($zh_dir);

        /** @var SplFileInfo $item */
        foreach ($zh_iterator as $item) {
            if ($item->isFile() && in_array($item->getExtension(), ['xml', 'ent'])) {
                $zh_file_map[$item->getBasename()] = $item->getPathname();
            }
        }
    }

    $en_iterator = new FilesystemIterator($en_dir);

    $tmp_en_iterator = [
        'dir' => [],
        "file" => []
    ];

    /** @var SplFileInfo $item */
    foreach ($en_iterator as $item) {
        if ($item->isDir()) {
            $tmp_en_iterator['dir'][$item->getBasename()] = $item;
        } else {
            if (!in_array($item->getExtension(), ['xml', 'ent']) || $item->getFilename()[0] === '.' || preg_match("/entities.*.xml/", $item->getFilename()) == 1) {
                continue;
            }

            $tmp_en_iterator['file'][$item->getBasename()] = $item;
        }
    }

    ksort($tmp_en_iterator['dir'], SORT_STRING);
    ksort($tmp_en_iterator['file'], SORT_STRING);

    // 循环目录
    foreach ($tmp_en_iterator['dir'] as $item) {
        foreachDir($en_dir . "/" . $item->getFilename(), $zh_dir . "/" . $item->getFilename(), $parent_root, $output);
    }

    // 循环文件
    foreach ($tmp_en_iterator['file'] as $item) {
        // 获取文件的 commit
        $en_commit_id = shell_exec("git -C {$item->getPath()} log -1 --pretty=%H ./{$item->getFilename()}");
        $en_commit_id = trim($en_commit_id);

        if (empty($en_commit_id)) {
            $output->writeln("出错: " . "cd {$item->getPath()} && git log -1 --pretty=%H ./{$item->getFilename()} ");
        }

        if (!isset($zh_file_map[$item->getFilename()])) {
            if ($item->getFilename() != 'versions.xml') {
                $output->writeln("缺失：" . substr($item->getPathname(), strlen($parent_root) + 1));
            }
            continue;
        }

        $zh_file = file_get_contents($zh_file_map[$item->getFilename()]);
        $zh_file = explode("\n", $zh_file);

        $zh_commit_id = '';
        foreach ($zh_file as $zh_file_item) {
            $zh_file_item = trim($zh_file_item);
            if (str_starts_with($zh_file_item, "<!-- EN-Revision:")) {
                $zh_file_item = trim(substr($zh_file_item, 17));
                $zh_commit_id = substr($zh_file_item, 0, strpos($zh_file_item, ' '));
                break;
            }
        }

        if ($zh_commit_id != $en_commit_id) {
            $output->writeln("翻译：" . substr($item->getPathname(), strlen($parent_root) + 1) . " " . $zh_commit_id);
        }

        unset($zh_file_map[$item->getFilename()]);
    }

    foreach ($zh_file_map as $item) {
        $output->writeln("删除：" . substr($item, strlen($parent_root) + 1));
    }
}

(new SingleCommandApplication())
    ->setName("docs-diff")
    ->setVersion("0.0.1")
    ->addOption("pull", 'p', InputOption::VALUE_NEGATABLE, "是否拉取最新 en 代码")
    ->addArgument("dir", InputArgument::IS_ARRAY, "目录名称，如果不指定则为全部", [])
    ->setCode(function (InputInterface $input, OutputInterface $output) {
        $parent_root = realpath(__DIR__ . "/../");
        $en_doc_root = realpath(__DIR__ . "/../en");
        $zh_doc_root = realpath(__DIR__ . "/../zh");

        // 判断是否拉取最新 en 代码
        $pull = $input->getOption("pull");
        if ($pull) {
            passthru("git -C $en_doc_root pull");
        }

        // 执行文件判断
        $dirs = $input->getArgument("dir");
        if ($dirs) {
            foreach ($dirs as $item) {
                $item = ltrim($item, './');
                foreachDir($en_doc_root . "/" . $item, $zh_doc_root . "/" . $item, $parent_root, $output);
            }
        } else {
            foreachDir($en_doc_root, $zh_doc_root, $parent_root, $output);
        }

    })->run();
