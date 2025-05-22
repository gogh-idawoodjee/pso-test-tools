import preset from './vendor/filament/support/tailwind.config.preset'
import colors from "tailwindcss/colors.js";
import defaultTheme from "tailwindcss/defaultTheme.js";

export default {
    presets: [preset],
    content: [
        './app/Filament/**/*.php',
        './resources/views/filament/**/*.blade.php',
        './vendor/filament/**/*.blade.php',
    ],
    theme: {
        extend: {
            colors: {
                green: colors.green,
                yellow: colors.yellow,
                neutral: colors.neutral,
                gray: colors.gray, // needed if using gray-600 etc.
                purple: colors.purple,
            },
            fontFamily: {
                sans: defaultTheme.fontFamily.sans,
            },
        },
    },
    safelist: [
        'bg-green-500',
        'bg-yellow-400',
        'bg-neutral-500',
        'text-yellow-600',
        'text-gray-600',
        'ring-yellow-300',

    ],
}
