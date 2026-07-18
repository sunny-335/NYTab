import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import './style.css'

// Vue DevUI — full registration (components + $message service + styles).
import DevUI from 'vue-devui'
import 'vue-devui/style.css'

const app = createApp(App)
app.use(createPinia())
app.use(router)
app.use(DevUI)
app.mount('#app')
