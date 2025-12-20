<?php

declare(strict_types=1);

namespace Cristal\Presentation\Resource;

/**
 * Chart resource class for handling DrawingML charts.
 *
 * @see ECMA-376 Part 1, Section 21.2 - DrawingML - Charts
 */
class Chart extends XmlResource
{
    /**
     * Chart types supported by PresentationML.
     */
    public const CHART_TYPES = [
        'bar' => 'barChart',
        'bar3D' => 'bar3DChart',
        'pie' => 'pieChart',
        'pie3D' => 'pie3DChart',
        'line' => 'lineChart',
        'line3D' => 'line3DChart',
        'area' => 'areaChart',
        'area3D' => 'area3DChart',
        'scatter' => 'scatterChart',
        'doughnut' => 'doughnutChart',
        'radar' => 'radarChart',
        'bubble' => 'bubbleChart',
        'stock' => 'stockChart',
        'surface' => 'surfaceChart',
        'surface3D' => 'surface3DChart',
    ];

    /**
     * Get the chart type.
     */
    public function getChartType(): ?string
    {
        $this->registerXPathNamespaces();

        foreach (self::CHART_TYPES as $type => $xmlTag) {
            $nodes = $this->content->xpath("//c:$xmlTag");
            if ($nodes !== false && !empty($nodes)) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Check if the chart is a 3D chart.
     */
    public function is3D(): bool
    {
        $type = $this->getChartType();

        return $type !== null && str_contains($type, '3D');
    }

    /**
     * Get chart title.
     */
    public function getTitle(): ?string
    {
        $this->registerXPathNamespaces();

        $titleNodes = $this->content->xpath('//c:title//a:t');

        if ($titleNodes === false || empty($titleNodes)) {
            return null;
        }

        $title = '';
        foreach ($titleNodes as $node) {
            $title .= (string) $node;
        }

        return !empty(trim($title)) ? trim($title) : null;
    }

    /**
     * Get all series in the chart.
     *
     * @return array<int, array{name: string, values: array<int, float|int|string>}>
     */
    public function getSeries(): array
    {
        $this->registerXPathNamespaces();
        $series = [];

        $serNodes = $this->content->xpath('//c:ser');

        if ($serNodes === false) {
            return [];
        }

        foreach ($serNodes as $ser) {
            $seriesData = [
                'name' => $this->getSeriesName($ser),
                'values' => $this->getSeriesValues($ser),
                'categories' => $this->getSeriesCategories($ser),
            ];

            $series[] = $seriesData;
        }

        return $series;
    }

    /**
     * Get the name of a series.
     *
     * @param \SimpleXMLElement $ser Series element
     */
    private function getSeriesName(\SimpleXMLElement $ser): string
    {
        $ser->registerXPathNamespace('c', 'http://schemas.openxmlformats.org/drawingml/2006/chart');

        $nameNodes = $ser->xpath('c:tx//c:v');
        if ($nameNodes !== false && !empty($nameNodes)) {
            return (string) $nameNodes[0];
        }

        // Fallback to string reference
        $strRefNodes = $ser->xpath('c:tx/c:strRef/c:strCache/c:pt/c:v');
        if ($strRefNodes !== false && !empty($strRefNodes)) {
            return (string) $strRefNodes[0];
        }

        return 'Series';
    }

    /**
     * Get the values of a series.
     *
     * @param \SimpleXMLElement $ser Series element
     * @return array<int, float|int>
     */
    private function getSeriesValues(\SimpleXMLElement $ser): array
    {
        $ser->registerXPathNamespace('c', 'http://schemas.openxmlformats.org/drawingml/2006/chart');
        $values = [];

        // Try numCache first (most common)
        $valueNodes = $ser->xpath('c:val/c:numRef/c:numCache/c:pt/c:v');

        if ($valueNodes === false || empty($valueNodes)) {
            // Try numLit
            $valueNodes = $ser->xpath('c:val/c:numLit/c:pt/c:v');
        }

        if ($valueNodes !== false) {
            foreach ($valueNodes as $v) {
                $val = (string) $v;
                $values[] = is_numeric($val) ? (str_contains($val, '.') ? (float) $val : (int) $val) : 0;
            }
        }

        return $values;
    }

    /**
     * Get the categories of a series.
     *
     * @param \SimpleXMLElement $ser Series element
     * @return array<int, string>
     */
    private function getSeriesCategories(\SimpleXMLElement $ser): array
    {
        $ser->registerXPathNamespace('c', 'http://schemas.openxmlformats.org/drawingml/2006/chart');
        $categories = [];

        // Try strCache first (most common for categories)
        $catNodes = $ser->xpath('c:cat/c:strRef/c:strCache/c:pt/c:v');

        if ($catNodes === false || empty($catNodes)) {
            // Try numCache (numeric categories)
            $catNodes = $ser->xpath('c:cat/c:numRef/c:numCache/c:pt/c:v');
        }

        if ($catNodes !== false) {
            foreach ($catNodes as $cat) {
                $categories[] = (string) $cat;
            }
        }

        return $categories;
    }

    /**
     * Get chart data as associative array.
     *
     * @return array<string, array<int, float|int|string>>
     */
    public function getData(): array
    {
        $data = [];

        foreach ($this->getSeries() as $series) {
            $data[$series['name']] = $series['values'];
        }

        return $data;
    }

    /**
     * Get all categories from the first series.
     *
     * @return array<int, string>
     */
    public function getCategories(): array
    {
        $series = $this->getSeries();

        return !empty($series) ? ($series[0]['categories'] ?? []) : [];
    }

    /**
     * Get series count.
     */
    public function getSeriesCount(): int
    {
        return count($this->getSeries());
    }

    /**
     * Check if the chart has a legend.
     */
    public function hasLegend(): bool
    {
        $this->registerXPathNamespaces();

        $legendNodes = $this->content->xpath('//c:legend');

        return $legendNodes !== false && !empty($legendNodes);
    }

    /**
     * Get legend position.
     *
     * @return string|null Position (r, l, t, b, tr) or null if no legend
     */
    public function getLegendPosition(): ?string
    {
        $this->registerXPathNamespaces();

        $posNodes = $this->content->xpath('//c:legend/c:legendPos/@val');

        return ($posNodes !== false && !empty($posNodes)) ? (string) $posNodes[0] : null;
    }

    /**
     * Check if the chart has data labels.
     */
    public function hasDataLabels(): bool
    {
        $this->registerXPathNamespaces();

        $dlblsNodes = $this->content->xpath('//c:dLbls');

        return $dlblsNodes !== false && !empty($dlblsNodes);
    }

    /**
     * Get plot area dimensions (if available).
     *
     * @return array{x: float|null, y: float|null, w: float|null, h: float|null}
     */
    public function getPlotAreaDimensions(): array
    {
        $this->registerXPathNamespaces();

        $layout = $this->content->xpath('//c:plotArea/c:layout/c:manualLayout');

        if ($layout === false || empty($layout)) {
            return ['x' => null, 'y' => null, 'w' => null, 'h' => null];
        }

        $layoutNode = $layout[0];
        $layoutNode->registerXPathNamespace('c', 'http://schemas.openxmlformats.org/drawingml/2006/chart');

        return [
            'x' => $this->getLayoutValue($layoutNode, 'x'),
            'y' => $this->getLayoutValue($layoutNode, 'y'),
            'w' => $this->getLayoutValue($layoutNode, 'w'),
            'h' => $this->getLayoutValue($layoutNode, 'h'),
        ];
    }

    /**
     * Get a layout value from manual layout.
     *
     * @param \SimpleXMLElement $layout Layout element
     * @param string $attribute Attribute name (x, y, w, h)
     */
    private function getLayoutValue(\SimpleXMLElement $layout, string $attribute): ?float
    {
        $nodes = $layout->xpath("c:$attribute/@val");

        return ($nodes !== false && !empty($nodes)) ? (float) $nodes[0] : null;
    }

    /**
     * Register XPath namespaces for querying.
     */
    private function registerXPathNamespaces(): void
    {
        $namespaces = $this->getNamespaces();

        foreach ($namespaces as $prefix => $uri) {
            if ($prefix !== '') {
                $this->content->registerXPathNamespace($prefix, $uri);
            }
        }

        // Ensure chart namespace is registered
        if (!isset($namespaces['c'])) {
            $this->content->registerXPathNamespace(
                'c',
                'http://schemas.openxmlformats.org/drawingml/2006/chart'
            );
        }
        if (!isset($namespaces['a'])) {
            $this->content->registerXPathNamespace(
                'a',
                'http://schemas.openxmlformats.org/drawingml/2006/main'
            );
        }
    }
}