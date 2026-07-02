import { createApp } from 'vue'
import { createPinia } from 'pinia'
import router from '@/router'
import App from '@/App.vue'
import '@/assets/styles/tailwind.css'
import 'sweetalert2/dist/sweetalert2.min.css'
import '@/assets/styles/swal.css'

createApp(App).use(createPinia()).use(router).mount('#app')
