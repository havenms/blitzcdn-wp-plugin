FROM oven/bun:1

WORKDIR /app

# Install system dependencies (unzip for zip extraction, curl for healthcheck)
RUN apt-get update && apt-get install -y \
    unzip \
    curl \
    && rm -rf /var/lib/apt/lists/*

# Copy package files
COPY package.json bun.lockb* ./

# Install dependencies
RUN bun install --production

# Copy source files
COPY . .

# Expose port
EXPOSE 3000

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD curl -f http://localhost:3000/health || exit 1

# Run server
CMD ["bun", "run", "server.ts"]
