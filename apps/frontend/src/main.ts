import './styles/tokens.css'
import './styles/components.css'
import './styles/home.css'
import './styles/screens.css'

import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import { router } from './router'

const app = createApp(App)

app.use(createPinia())
app.use(router)

app.mount('#app')
