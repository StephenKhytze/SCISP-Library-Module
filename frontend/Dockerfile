FROM node:20-alpine

# Set working directory
WORKDIR /app

# The application code will be mounted here via docker-compose volume
# We expose port 5173 and run the Vite development server
EXPOSE 5173

# We run npm run dev with the --host flag to allow access from outside the container
CMD ["npm", "run", "dev", "--", "--host"]
