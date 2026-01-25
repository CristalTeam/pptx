<?php
function getSlideIds($path) {
    $zip = new ZipArchive();
    $zip->open($path);
    $content = $zip->getFromName('ppt/presentation.xml');
    preg_match('/<p:sldIdLst[^>]*>(.*?)<\/p:sldIdLst>/s', $content, $listMatch);
    if (empty($listMatch)) return [];
    preg_match_all('/<p:sldId[^>]+id="([^"]+)"[^>]+r:id="([^"]+)"/', $listMatch[1], $matches, PREG_SET_ORDER);
    $result = [];
    foreach ($matches as $m) {
        $result[$m[2]] = $m[1]; // rId => id
    }
    $zip->close();
    return $result;
}

echo "=== Corrupt slideIdList ===" . PHP_EOL;
$corrupt = getSlideIds('tests/tmp/merge.pptx');
foreach ($corrupt as $rId => $id) {
    echo "$rId => id=$id" . PHP_EOL;
}

echo PHP_EOL . "=== Repaired slideIdList ===" . PHP_EOL;
$repaired = getSlideIds('tests/tmp/merge_repaired.pptx');
foreach ($repaired as $rId => $id) {
    echo "$rId => id=$id" . PHP_EOL;
}

echo PHP_EOL . "=== Differences ===" . PHP_EOL;
foreach ($corrupt as $rId => $id) {
    if (!isset($repaired[$rId]) || $repaired[$rId] !== $id) {
        echo "Diff at $rId: corrupt=$id, repaired=" . ($repaired[$rId] ?? 'MISSING') . PHP_EOL;
    }
}
