FROM node:22-alpine
WORKDIR /app
ENV NODE_ENV=production DATA_DIR=/data TZ=America/Chicago
COPY package*.json ./
RUN npm ci --omit=dev || npm install --omit=dev
COPY . .
VOLUME /data
EXPOSE 3000
CMD ["node", "server.js"]
