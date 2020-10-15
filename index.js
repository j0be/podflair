const phpServer = require('php-server');

(async () => {
    const server = await phpServer({
        port: 55340,
        hostname: '127.0.0.1',
        base: '.'
    });
    console.log(`PHP server running at ${server.url}`)
})();