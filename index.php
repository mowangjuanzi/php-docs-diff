<?php

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\SingleCommandApplication;

require __DIR__ . "/vendor/autoload.php";

const FlagTranslate = 1 << 0; // 翻译待更新的
const FlagMissing = 1 << 1; // 未翻译的
const FlagDelete = 1 << 2; // 待删除的

/**
 * 循环内容
 * @param string $en_dir 英文目录
 * @param string $zh_dir 中文目录
 * @param string $parent_root 根目录
 * @param OutputInterface $output Symfony/Console 的输出对象
 * @param int $flags 设置输出格式 默认 FlagTranslate | FlagMissing | FlagDelete
 * @return void
 */
function foreachDir(string $en_dir, string $zh_dir, string $parent_root, OutputInterface $output, int $flags = 0)
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
        if ($item->isDir() && $item->getBasename()[0] != '.') { // 过滤隐藏文件
            $tmp_en_iterator['dir'][$item->getBasename()] = $item;
        } elseif ($item->isFile() && in_array($item->getExtension(), ['xml', 'ent']) && preg_match("/entities.*.xml/", $item->getFilename()) == 0) { // 过滤扩展名不正确或者文件名应该屏蔽掉的文件名
            $tmp_en_iterator['file'][$item->getBasename()] = $item;
        }
    }

    ksort($tmp_en_iterator['dir'], SORT_STRING);
    ksort($tmp_en_iterator['file'], SORT_STRING);

    // 循环目录
    foreach ($tmp_en_iterator['dir'] as $item) {
        foreachDir($en_dir . "/" . $item->getFilename(), $zh_dir . "/" . $item->getFilename(), $parent_root, $output, $flags);
    }

    // 循环文件
    foreach ($tmp_en_iterator['file'] as $item) {
        // 获取文件的 commit
        $en_commit_id = shell_exec("git -C {$item->getPath()} log -1 --pretty=%H ./{$item->getFilename()}");
        $en_commit_id = trim($en_commit_id);

        if (empty($en_commit_id)) {
            $output->writeln("出错: " . "cd {$item->getPath()} && git log -1 --pretty=%H ./{$item->getFilename()} ");
        }

        // version.xml 不用翻译
        if ($item->getFilename() == 'versions.xml') {
            if (isset($zh_file_map['versions.xml'])) {
                $output->writeln("删除：" . substr($item, strlen($parent_root) + 1));
            };

            continue;
        }

        if (!isset($zh_file_map[$item->getFilename()])) {
            if ($flags & FlagMissing) {
                $output->writeln("缺失：" . substr($item->getPathname(), strlen($parent_root) + 1));
            }
            continue;
        }

        if ($flags & FlagTranslate) {
            $zh_file = file_get_contents($zh_file_map[$item->getFilename()]);
            $zh_file = explode("\n", $zh_file);

            $zh_commit_id = '';
            foreach ($zh_file as $zh_file_item) {
                $zh_file_item = trim($zh_file_item);
                if (str_starts_with($zh_file_item, "<!-- EN-Revision:")) {
                    $zh_file_item = trim(substr($zh_file_item, 17));
                    $zh_commit_id = substr($zh_file_item, 0, strpos($zh_file_item, ' '));
                    break;
                } elseif (str_starts_with($zh_file_item, "<!-- \$EN-Revision:")) {
                    $zh_file_item = trim(substr($zh_file_item, 18));
                    $zh_commit_id = substr($zh_file_item, 0, strpos($zh_file_item, ' '));
                    break;
                }
            }

            if ($zh_commit_id != $en_commit_id) {
                $output->writeln("翻译：" . substr($item->getPathname(), strlen($parent_root) + 1) . " " . $zh_commit_id);
            }
        }

        unset($zh_file_map[$item->getFilename()]);
    }

    if ($flags & FlagDelete) {
        foreach ($zh_file_map as $item) {
            $output->writeln("删除：" . substr($item, strlen($parent_root) + 1));
        }
    }
}

(new SingleCommandApplication())
    ->setName("docs-diff")
    ->setVersion("0.0.2")
    ->addOption("pull", 'p', InputOption::VALUE_NEGATABLE, "是否拉取最新 en 代码")
    ->addOption("missing", 'm', InputOption::VALUE_NEGATABLE, "是否忽略未翻译的")
    ->addOption("translate", 't', InputOption::VALUE_NEGATABLE, "是否忽略未翻译到最新的")
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

        $flags = FlagDelete | FlagTranslate;

        $flag_miss = $input->getOption('missing');
        if ($flag_miss != true) {
            $flags = $flags | FlagMissing;
        }

        // 执行文件判断
        $dirs = $input->getArgument("dir");
        if ($dirs) {
            foreach ($dirs as $item) {
                $item = ltrim($item, './');
                foreachDir($en_doc_root . "/" . $item, $zh_doc_root . "/" . $item, $parent_root, $output,  $flags);
            }
        } else {
            foreachDir($en_doc_root, $zh_doc_root, $parent_root, $output, $flags);
        }

    })->run();
