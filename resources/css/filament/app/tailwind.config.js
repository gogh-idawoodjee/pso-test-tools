import colors from 'tailwindcss/colors'
import defaultTheme from 'tailwindcss/defaultTheme'
import preset from '../../../../vendor/filament/filament/tailwind.config.preset'

export default {
    presets: [preset],
    content: [
        './resources/**/*.blade.php',
        './app/**/*.php',
        './vendor/filament/**/*.blade.php',
    ],
    safelist: [
        'bg-green-500',
        'bg-yellow-400',
        'bg-neutral-500',
        'text-yellow-600',
        'text-gray-600',
        'ring-yellow-300',
    ],
    theme: {
        extend: {
            colors: {
                green: colors.green,
                yellow: colors.yellow,
                neutral: colors.neutral,
                gray: colors.gray,
            },
            fontFamily: {
                sans: defaultTheme.fontFamily.sans,
            },
        },
    },
}
