<?php

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\SingleCommandApplication;

require __DIR__ . "/vendor/autoload.php";

function foreachDir($en_dir, $zh_dir, $parent_root)
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

    /** @var SplFileInfo $item */
    foreach ($en_iterator as $item) {
        if ($item->isDir()) {
            foreachDir($en_dir . "/" . $item->getFilename(), $zh_dir . "/" . $item->getFilename(), $parent_root);
        } else {
            if (!in_array($item->getExtension(), ['xml', 'ent']) || preg_match("/entities.*.xml/", $item->getFilename()) == 1) {
                continue;
            }

            // 获取文件的 commit
            $en_commit_id = shell_exec("git -C {$item->getPath()} log -1 --pretty=%H ./{$item->getFilename()}");
            $en_commit_id = trim($en_commit_id);

            if (empty($en_commit_id)) {
                dd("1111", "cd {$item->getPath()} && git log -1 --pretty=%H ./{$item->getFilename()} ");
            }

            if (!isset($zh_file_map[$item->getFilename()])) {
                if ($item->getFilename() != 'versions.xml') {
                    dump("缺失：" . substr($item->getPathname(), strlen($parent_root) + 1));
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
                dump("翻译：" . substr($item->getPathname(), strlen($parent_root) + 1) . " " . $zh_commit_id);
            }

            unset($zh_file_map[$item->getFilename()]);
        }
    }

    foreach ($zh_file_map as $item) {
        dump("删除：" . substr($item, strlen($parent_root) + 1));
    }
}

(new SingleCommandApplication())
    ->setName("docs-diff")
    ->setVersion("0.0.1")
    ->addOption("pull", 'p', InputOption::VALUE_NEGATABLE, "是否拉取最新 en 代码")
    ->addArgument("dir", InputArgument::IS_ARRAY, "目录名称，如果不指定则为全部", [])
    ->setCode(function (InputInterface $input, OutputInterface $output) {
        $parent_root = realpath("../");
        $en_doc_root = realpath("../en");
        $zh_doc_root = realpath("../zh");

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
                foreachDir($en_doc_root . "/" . $item, $zh_doc_root . "/" . $item, $parent_root);
            }
        } else {
            foreachDir($en_doc_root, $zh_doc_root, $parent_root);
        }

    })->run();