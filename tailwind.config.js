/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    "./index.php",
    "./pages/**/*.php",
    "./includes/**/*.php",
    "./classes/**/*.php"
  ],
  theme: {
    extend: {
      colors: {
        // Palette 3CE FISCUS — dérivée de l'encre du logo (#1d3a5f)
        primary: {
          50: '#f3f7fb', 100: '#e4edf6', 200: '#c6d9eb', 300: '#9abcd9',
          400: '#6796c1', 500: '#4577a8', 600: '#345f8d', 700: '#2a4c74',
          800: '#1d3a5f', 900: '#1a3150',
        }
      }
    },
  },
  plugins: [],
}
