import { dirname, resolve as _resolve, join } from 'path';
import { fileURLToPath } from 'url';
import TerserPlugin from 'terser-webpack-plugin';
import MiniCssExtractPlugin from 'mini-css-extract-plugin';
import CssMinimizerPlugin from 'css-minimizer-webpack-plugin';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

const mode = process.argv.includes('--mode=production') ?
  'production' :
  'development';

export default {
  mode: mode,
  resolve: {
    alias: {
      '@assets': _resolve(__dirname, 'src/assets'),
      '@components': _resolve(__dirname, 'src/scripts/components'),
      '@mixins': _resolve(__dirname, 'src/scripts/mixins'),
      '@models': _resolve(__dirname, 'src/scripts/models'),
      '@root': _resolve(__dirname, './'),
      '@scripts': _resolve(__dirname, 'src/scripts'),
      '@services': _resolve(__dirname, 'src/scripts/services'),
      '@styles': _resolve(__dirname, 'src/styles')
    }
  },
  optimization: {
    minimize: mode === 'production',
    minimizer: [
      new TerserPlugin({
        terserOptions: {
          compress: {
            drop_console: true,
          }
        }
      }),
      new CssMinimizerPlugin()
    ]
  },
  entry: {
    'simpleh5pstats-confirmation-dialog': './src/scripts/confirmation-dialog.js',
    'simpleh5pstats-listener': './src/scripts/listener.js',
    'simpleh5pstats-options': './src/scripts/options.js',
    'simpleh5pstats-table-view': './src/scripts/table-view.js',
  },
  output: {
    filename: '[name].js',
    path: _resolve(__dirname, 'js'),
    clean: true
  },
  target: ['browserslist'],
  module: {
    rules: [
      {
        test: /\.(css)$/,
        use: [
          {
            loader: MiniCssExtractPlugin.loader,
          },
          {
            loader: 'css-loader',
          }
        ]
      }
    ]
  },
  plugins: [
    new MiniCssExtractPlugin({
      filename: join('..', 'styles', '[name].css')
    })
  ],
  stats: {
    colors: true
  },
  ...(mode !== 'production' && { devtool: 'eval-cheap-module-source-map' })
};
