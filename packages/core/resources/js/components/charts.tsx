import { areaY, barY, defineChart, lineY } from '@tanstack/charts';
import { Chart } from '@tanstack/charts/react';
import { scaleBand } from '@tanstack/charts/scales/band';
import { scaleLinear } from '@tanstack/charts/scales/linear';
import { scalePoint } from '@tanstack/charts/scales/point';
import { tooltip } from '@tanstack/charts/tooltip';
import { useMemo } from 'react';
import { cn } from '@/lib/utils';

export type DailyCount = { date: string; count: number };

const shortDate = new Intl.DateTimeFormat(undefined, {
    day: 'numeric',
    month: 'short',
});

/** "7 Oct" for an ISO day such as 2026-10-07, read as a calendar date so time zones cannot shift it. */
function dayLabel(date: string): string {
    const [year, month, day] = date.split('-').map(Number);

    return shortDate.format(new Date(year, month - 1, day));
}

/** One label a week, counted back from the latest day so today is always labelled. */
function weeklyTicks(data: DailyCount[]): string[] {
    return data
        .filter((_, index) => (data.length - 1 - index) % 7 === 0)
        .map((day) => day.date);
}

/**
 * A small bar sparkline of daily counts, without axes, for cards. It takes the text colour, so set `className`
 * to tint it, and reads to screen readers as its label.
 */
export function Sparkline({
    data,
    label,
    height = 36,
    className,
}: {
    data: DailyCount[];
    label: string;
    height?: number;
    className?: string;
}) {
    const definition = useMemo(
        () =>
            defineChart({
                marks: [
                    barY(data, {
                        x: 'date',
                        y: 'count',
                        fill: 'currentColor',
                        fillOpacity: 0.7,
                    }),
                ],
                scales: {
                    x: {
                        scale: () => scaleBand<string>().padding(0.25),
                        axis: false,
                    },
                    y: { scale: scaleLinear, axis: false },
                },
                margin: 0,
            }),
        [data],
    );

    return (
        <Chart
            definition={definition}
            ariaLabel={label}
            height={height}
            initialWidth={240}
            className={cn('text-muted-foreground/60', className)}
        />
    );
}

/**
 * Daily counts over time as a soft area with a line on top, light gridlines and a tooltip on hover.
 */
export function TrendChart({
    data,
    label,
    height = 200,
    className,
}: {
    data: DailyCount[];
    label: string;
    height?: number;
    className?: string;
}) {
    const definition = useMemo(
        () =>
            defineChart({
                marks: [
                    areaY(data, {
                        x: 'date',
                        y: 'count',
                        fill: 'currentColor',
                        fillOpacity: 0.08,
                    }),
                    lineY(data, {
                        x: 'date',
                        y: 'count',
                        stroke: 'currentColor',
                        strokeWidth: 1.75,
                    }),
                ],
                scales: {
                    x: {
                        scale: () => scalePoint<string>(),
                        axis: {
                            ticks: {
                                size: 0,
                                padding: 8,
                                values: weeklyTicks(data),
                                format: dayLabel,
                            },
                            line: false,
                        },
                    },
                    y: {
                        scale: scaleLinear,
                        nice: true,
                        grid: true,
                        axis: {
                            ticks: {
                                size: 0,
                                format: (value: number) =>
                                    value.toLocaleString(),
                            },
                            line: false,
                        },
                    },
                },
                tooltip,
            }),
        [data],
    );

    return (
        <Chart
            definition={definition}
            ariaLabel={label}
            height={height}
            initialWidth={720}
            className={cn('text-foreground [&_text]:text-xs', className)}
        />
    );
}
