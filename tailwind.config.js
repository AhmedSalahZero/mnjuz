/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    "./resources/**/*.blade.php",
    "./resources/**/*.js",
    "./resources/**/*.vue",
    "./src/**/*.{html,js}",
  ],
  theme: {
    extend: {
      colors: {
        primary: '#034737',
        secondary: '#008000',
      },
      fontFamily: {
        'Outfit': ['Outfit', 'Helvetica', 'Arial', 'sans-serif'],
      },
      lineHeight: {
        '15': '3rem',  // Custom line height class
        '20': '65px',     // Custom line height class
        // Add more custom line height classes as needed
      },
    },  
  },
  plugins: [],
  darkMode: "class"
}