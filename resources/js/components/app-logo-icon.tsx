import type { SVGAttributes } from 'react';

/**
 * A neutral envelope mark that stands in until the product has its own logo. Drawn with strokes,
 * so it takes the text colour and ignores fill classes.
 */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
            <path
                d="M4.75 5.5h14.5c.97 0 1.75.78 1.75 1.75v9.5c0 .97-.78 1.75-1.75 1.75H4.75C3.78 18.5 3 17.72 3 16.75v-9.5c0-.97.78-1.75 1.75-1.75Z"
                fill="none"
                stroke="currentColor"
                strokeWidth="1.75"
                strokeLinejoin="round"
            />
            <path
                d="m3.75 7 7.2 5.4c.62.47 1.48.47 2.1 0L20.25 7"
                fill="none"
                stroke="currentColor"
                strokeWidth="1.75"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
        </svg>
    );
}
