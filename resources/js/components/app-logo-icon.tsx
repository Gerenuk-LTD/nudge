import type { SVGAttributes } from 'react';

/**
 * The Nudge paper plane. The upper face takes the text colour so it reads on light and dark
 * backgrounds; the fold is always the brand green.
 */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg
            {...props}
            viewBox="225 235 574 574"
            xmlns="http://www.w3.org/2000/svg"
        >
            <path d="M789 256 235 490l213 85Z" fill="currentColor" />
            <path d="M789 256 448 575l85 214Z" fill="#0E9F6E" />
        </svg>
    );
}
