/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    "./resources/**/*.blade.php",
    "./resources/**/*.js",
    "./resources/**/*.vue",
    // الوحدات (modules/FlowBuilder وغيرها) فيها واجهات بكلاسات Tailwind؛ بلا هذا
    // لا يولّد الـ build إلا ما صادف وجوده في resources، فتفقد عقد المسارات
    // حدودها وألوانها (border-teal-200، !bg-teal-500، w-[400px]…).
    "./modules/**/*.vue",
    "./modules/**/*.js",
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