// PCS Computers brand colors for Tailwind. Load right after the Tailwind CDN script.
// "indigo" is the system's main accent color, so it is remapped to the PCS green;
// "pcs" holds the dark green of the logo background, used by the sidebar and sign-in pages.
if (window.tailwind) {
  tailwind.config = {
    theme: {
      extend: {
        colors: {
          indigo: {
            50: '#eef8f0', 100: '#d7efdc', 200: '#b0dfba', 300: '#80c991', 400: '#52b16a',
            500: '#36974f', 600: '#257d3d', 700: '#1f6433', 800: '#1b4f2b', 900: '#173f24', 950: '#0b2414',
          },
          pcs: {
            50: '#f2f5f3', 100: '#e4ebe6', 200: '#c9d8ce', 300: '#a3bcac', 400: '#7f9d8a', 500: '#5c7d69',
            600: '#3f6050', 700: '#2c4b3d', 800: '#223f32', 900: '#1b3429', 950: '#13261e', blue: '#8fa8cc',
          },
        },
      },
    },
  };
}
