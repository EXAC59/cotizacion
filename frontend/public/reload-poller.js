;(function () {
  var moduleScript = document.querySelector('script[type="module"]')
  if (!moduleScript) return

  var loadedSrc = moduleScript.getAttribute('src') || ''

  function checkForDeploy() {
    fetch('/spa/index.html', { cache: 'no-store' })
      .then(function (res) {
        return res.text()
      })
      .then(function (html) {
        var match = html.match(/assets\/index-[^"']+\.js/)
        if (!match) return
        if (loadedSrc.indexOf(match[0]) === -1) {
          window.location.reload()
        }
      })
      .catch(function () {})
  }

  checkForDeploy()
  window.setInterval(checkForDeploy, 30_000)
})()
