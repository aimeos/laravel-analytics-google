<?php

namespace Aimeos\AnalyticsBridge\Drivers;

use Aimeos\AnalyticsBridge\Contracts\Driver;
use Google\Service\AnalyticsData;
use Google\Client;


class Ga4 implements Driver
{
    private ?string $propertyId;
    private ?AnalyticsData $analytics = null;
    private array $types = [];


    public function __construct(array $config = [])
    {
        $this->propertyId = $config['propertyid'] ?? $config['propertyId'] ?? null;
        $auth = $config['credentials'] ?? $config['auth'] ?? null;

        if (!empty($auth)) {
            $client = new Client();
            $client->setAuthConfig($auth);
            $client->addScope('https://www.googleapis.com/auth/analytics.readonly');

            $this->analytics = new AnalyticsData($client);
        }
    }


    public function types(array $types): Driver
    {
        $this->types = $types;
        return $this;
    }


    public function stats(string $url, int $days = 30): ?array
    {
        if (!$this->propertyId || !$this->analytics) {
            return null;
        }

        $types = !empty($this->types)
            ? $this->types
            : ['views', 'visits', 'durations', 'conversions', 'countries', 'referrers', 'links'];

        $result = [];
        $daysRange = $this->dateRange($days);

        // --- 1️⃣ Daily metrics (combine into single batch request) ---
        $dailyMetricsMap = [];
        if (in_array('views', $types)) $dailyMetricsMap['views'] = 'screenPageViews';
        if (in_array('visits', $types)) $dailyMetricsMap['visits'] = 'sessions';
        if (in_array('durations', $types)) $dailyMetricsMap['durations'] = 'averageSessionDuration';
        if (in_array('conversions', $types)) $dailyMetricsMap['conversions'] = 'keyEvents';

        if (!empty($dailyMetricsMap)) {
            // Single RunReportRequest with multiple metrics
            $request = new AnalyticsData\RunReportRequest([
                'dimensions' => [new AnalyticsData\Dimension(['name' => 'date'])],
                'metrics' => array_map(fn($m) => new AnalyticsData\Metric(['name' => $m]), $dailyMetricsMap),
                'dateRanges' => [new AnalyticsData\DateRange([
                    'startDate' => $daysRange[0],
                    'endDate' => $daysRange[1]
                ])],
                'dimensionFilter' => $this->pageFilter($url)
            ]);

            $report = $this->analytics->properties->runReport('properties/' . $this->propertyId, $request);

            foreach ($dailyMetricsMap as $key => $metric) {
                $result[$key] = collect($report->getRows() ?? [])
                    ->map(fn($row) => [
                        'key' => \DateTime::createFromFormat('Ymd', $row->getDimensionValues()[0]->getValue())->format('Y-m-d'),
                        'value' => (float) $row->getMetricValues()[array_search($metric, array_values($dailyMetricsMap))]->getValue(),
                    ])->all();
            }
        }

        // --- 2️⃣ Countries aggregate ---
        if (in_array('countries', $types)) {
            $report = $this->analytics->properties->runReport(
                'properties/' . $this->propertyId,
                $this->makeAggregateRequest('country', 'sessions', $daysRange, $url)
            );
            $result['countries'] = $this->mapAggregate($report);
        }

        // --- 3️⃣ Referrers (Matomo-style) ---
        if (in_array('referrers', $types)) {
            $report = $this->analytics->properties->runReport(
                'properties/' . $this->propertyId,
                $this->makeReferrerRequest($daysRange, $url)
            );
            $result['referrers'] = $this->mapReferrers($report);
        }

        // --- 4️⃣ Links (next internal pages, outbound clicks and downloads) ---
        if (in_array('links', $types)) {
            $request = new AnalyticsData\BatchRunReportsRequest([
                'requests' => [
                    $this->makeFollowingRequest($daysRange, $url),
                    $this->makeClickRequest($daysRange, $url),
                ]
            ]);

            $reports = $this->analytics->properties->batchRunReports('properties/' . $this->propertyId, $request)->getReports();
            $result['links'] = $this->mapLinks($reports[0], $reports[1], $url);
        }

        return $result;
    }


    protected function makeFollowingRequest(array $daysRange, string $url): AnalyticsData\RunReportRequest
    {
        // Page views whose referrer is the page itself
        return new AnalyticsData\RunReportRequest([
            'dimensions' => [
                new AnalyticsData\Dimension(['name' => 'hostName']),
                new AnalyticsData\Dimension(['name' => 'pagePath']),
            ],
            'metrics' => [ new AnalyticsData\Metric(['name' => 'screenPageViews']) ],
            'dateRanges' => [ new AnalyticsData\DateRange([
                'startDate' => $daysRange[0],
                'endDate'   => $daysRange[1]
            ]) ],
            'dimensionFilter' => new AnalyticsData\FilterExpression([
                'filter' => new AnalyticsData\Filter([
                    'fieldName' => 'pageReferrer',
                    'stringFilter' => new AnalyticsData\StringFilter([
                        'matchType' => 'EXACT',
                        'value' => $url
                    ])
                ])
            ]),
            'limit' => 100,
        ]);
    }


    protected function makeClickRequest(array $daysRange, string $url): AnalyticsData\RunReportRequest
    {
        // Outbound clicks and downloads tracked by enhanced measurement
        return new AnalyticsData\RunReportRequest([
            'dimensions' => [ new AnalyticsData\Dimension(['name' => 'linkUrl']) ],
            'metrics' => [ new AnalyticsData\Metric(['name' => 'eventCount']) ],
            'dateRanges' => [ new AnalyticsData\DateRange([
                'startDate' => $daysRange[0],
                'endDate'   => $daysRange[1]
            ]) ],
            'dimensionFilter' => new AnalyticsData\FilterExpression([
                'andGroup' => new AnalyticsData\FilterExpressionList([
                    'expressions' => [
                        $this->pageFilter($url),
                        new AnalyticsData\FilterExpression([
                            'filter' => new AnalyticsData\Filter([
                                'fieldName' => 'eventName',
                                'inListFilter' => new AnalyticsData\InListFilter([
                                    'values' => ['click', 'file_download']
                                ])
                            ])
                        ]),
                    ]
                ])
            ]),
            'limit' => 100,
        ]);
    }


    protected function makeAggregateRequest(string $dimension, string $metric, array $daysRange, string $url): AnalyticsData\RunReportRequest
    {
        return new AnalyticsData\RunReportRequest([
            'dimensions' => [ new AnalyticsData\Dimension(['name' => $dimension]) ],
            'metrics' => [ new AnalyticsData\Metric(['name' => $metric]) ],
            'dateRanges' => [ new AnalyticsData\DateRange([
                'startDate' => $daysRange[0],
                'endDate'   => $daysRange[1]
            ]) ],
            'dimensionFilter' => $this->pageFilter($url),
        ]);
    }


    protected function makeReferrerRequest(array $daysRange, string $url): AnalyticsData\RunReportRequest
    {
        return new AnalyticsData\RunReportRequest([
            'dimensions' => [
                new AnalyticsData\Dimension(['name' => 'sessionDefaultChannelGroup']),
                new AnalyticsData\Dimension(['name' => 'sessionSource']),
            ],
            'metrics' => [ new AnalyticsData\Metric(['name' => 'sessions']) ],
            'dateRanges' => [ new AnalyticsData\DateRange([
                'startDate' => $daysRange[0],
                'endDate'   => $daysRange[1]
            ]) ],
            'dimensionFilter' => $this->pageFilter($url),
        ]);
    }


    protected function pageFilter(string $url): AnalyticsData\FilterExpression
    {
        // Filter by full URL using pageLocation
        return new AnalyticsData\FilterExpression([
            'filter' => new AnalyticsData\Filter([
                'fieldName' => 'pageLocation',
                'stringFilter' => new AnalyticsData\StringFilter([
                    'matchType' => 'EXACT',
                    'value' => $url
                ])
            ])
        ]);
    }


    protected function dateRange(int $days): array
    {
        $end   = (new \DateTime('yesterday'))->format('Y-m-d');
        $start = (new \DateTime("yesterday - " . ($days - 1) . " days"))->format('Y-m-d');
        return [$start, $end];
    }


    protected function mapAggregate(AnalyticsData\RunReportResponse $report): array
    {
        return collect($report->getRows() ?? [])
            ->map(fn($row) => [
                'key' => $row->getDimensionValues()[0]->getValue(),
                'value' => (float)$row->getMetricValues()[0]->getValue(),
            ])
            ->all();
    }

    protected function mapLinks(AnalyticsData\RunReportResponse $following, AnalyticsData\RunReportResponse $clicks, string $url): array
    {
        $self = parse_url($url, PHP_URL_HOST) . parse_url($url, PHP_URL_PATH);

        $pages = collect($following->getRows() ?? [])->map(fn($row) => [
            'key' => $row->getDimensionValues()[0]->getValue() . $row->getDimensionValues()[1]->getValue(),
            'value' => (float) $row->getMetricValues()[0]->getValue(),
        ])->filter(fn($item) => $item['key'] !== $self); // reloads

        $links = collect($clicks->getRows() ?? [])->map(fn($row) => [
            'key' => $row->getDimensionValues()[0]->getValue(),
            'value' => (float) $row->getMetricValues()[0]->getValue(),
        ]);

        return $pages->concat($links)->sortByDesc('value')->values()->all();
    }


    protected function mapReferrers(AnalyticsData\RunReportResponse $report): array
    {
        $rows = collect($report->getRows() ?? []);
        $grouped = $rows->groupBy(fn($row) => $row->getDimensionValues()[0]->getValue());

        return $grouped->map(fn($rows, $channel) => [
            'key' => $channel,
            'value' => $rows->sum(fn($row) => (float)$row->getMetricValues()[0]->getValue()),
            'rows' => $rows->map(fn($row) => [
                'key' => $row->getDimensionValues()[1]->getValue(),
                'value' => (float)$row->getMetricValues()[0]->getValue(),
            ])->values()->all(),
        ])->values()->all();
    }
}
